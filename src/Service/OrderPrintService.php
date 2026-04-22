<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Address;
use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\DisplayQueue;
use ControleOnline\Entity\Document;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\OrderProductQueue;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Phone;
use ControleOnline\Entity\Spool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrderPrintService
{
    private string $defaultGroupName = 'ITENS';
    private string $defaultChildGroupName = 'OBSERVACOES';
    private string $defaultQueueName = 'SEM FILA DEFINIDA';
    private string $displayDeviceType = 'DISPLAY';
    private string $displayConfigKey = 'display-id';
    private string $printerConfigKey = 'printer';
    private string $displayAutoPrintProductConfigKey = 'display-auto-print-product';
    private int $contentWidth = 40;
    private array $extraDataCache = [];

    public function __construct(
        private EntityManagerInterface $manager,
        private PrintService $printService,
        private ConfigService $configService,
        private DeviceService $deviceService,
        private ExtraDataService $extraDataService,
    ) {}

    private function resolveAdditionalDataDeviceType(?array $aditionalData = []): ?string
    {
        $candidate = trim((string) (
            $aditionalData['type'] ??
            $aditionalData['deviceType'] ??
            ''
        ));

        return $candidate !== '' ? strtoupper($candidate) : null;
    }

    private function resolvePrintMode(
        Device $device,
        People $provider,
        ?string $type = null
    ): string
    {
        $deviceConfig = $this->deviceService->findDeviceConfig($device, $provider, $type);
        $configs = $deviceConfig?->getConfigs(true);

        return is_array($configs)
            ? ($configs['print-mode'] ?? 'order')
            : 'order';
    }

    private function resolvePrintTargets(
        array|string $deviceReferences,
        People $provider
    ): array {
        $references = is_array($deviceReferences) ? $deviceReferences : [$deviceReferences];
        $targets = [];

        foreach ($references as $reference) {
            $deviceConfig = $this->deviceService->findDeviceConfigByReference(
                $reference,
                $provider
            );

            if ($deviceConfig instanceof DeviceConfig) {
                $targetDevice = $deviceConfig->getDevice();
                $targetType = strtoupper(trim((string) $deviceConfig->getType()));
                $targetKey = $targetDevice->getId() . '::' . $targetType;

                $targets[$targetKey] = [
                    'device' => $targetDevice,
                    'type' => $targetType,
                ];
                continue;
            }

            if ($this->deviceService->isDeviceConfigReference($reference)) {
                continue;
            }

            foreach ($this->deviceService->findDevices($reference) as $targetDevice) {
                $targets[$targetDevice->getId()] = [
                    'device' => $targetDevice,
                    'type' => null,
                ];
            }
        }

        return array_values($targets);
    }

    public function printConferenceCopies(
        Order $order,
        ?array $devices = [],
        ?array $aditionalData = []
    ): int
    {
        $resolvedTargets = $this->resolveConferencePrintTargets($order, $devices);
        $printedCount = 0;

        foreach ($resolvedTargets as $target) {
            $this->generatePrintData(
                $order,
                $target['device'],
                array_merge(
                    $aditionalData,
                    !empty($target['type']) ? ['type' => $target['type']] : []
                )
            );
            $printedCount++;
        }

        return $printedCount;
    }

    public function printOrder(Order $order, ?array $devices = [], ?array $aditionalData = []): void
    {
        $hasExplicitDevices = !empty($devices);
        $this->printConferenceCopies($order, $devices, $aditionalData);

        if (!$hasExplicitDevices) {
            $this->printDisplayCopies($order, $aditionalData);
        }
    }

    private function resolveConferencePrintTargets(
        Order $order,
        ?array $devices = []
    ): array {
        if (!empty($devices)) {
            return $this->resolvePrintTargets(
                $devices,
                $order->getProvider()
            );
        }

        $configuredDevices = $this->configService->getConfig(
            $order->getProvider(),
            'order-print-devices',
            true
        ) ?? [];

        if (empty($configuredDevices)) {
            return [];
        }

        return $this->resolvePrintTargets(
            $configuredDevices,
            $order->getProvider()
        );
    }

    public function generatePrintDataFromContent(
        Order $order,
        ?string $content
    ): ?Spool
    {
        return $this->generatePrintDataFromPayload(
            $order,
            $this->decodePayload($content)
        );
    }

    public function generatePrintDataFromPayload(
        Order $order,
        array $payload
    ): ?Spool
    {
        $device = $this->resolvePayloadDevice($payload);
        $queueIds = $this->normalizeIds($payload['queueIds'] ?? []);

        if (!empty($queueIds)) {
            return $this->generateQueuePrintData($order, $device, $queueIds);
        }

        return $this->generatePrintData($order, $device);
    }

    public function generatePrintData(Order $order, Device $device, ?array $aditionalData = []): Spool
    {
        $printMode = $this->resolvePrintMode(
            $device,
            $order->getProvider(),
            $this->resolveAdditionalDataDeviceType($aditionalData)
        );
        $printForm = $printMode === 'form';

        $this->printProviderHeader($order->getProvider());
        $this->printOrderHeader($order, $printForm);
        $this->printClientAddress($order);
        $this->printMarketplaceHeader($order);
        $this->printOrderComments($order, $printForm);
        $this->printSeparator();

        $groups = $this->getGroups($order);
        $this->printGroups($groups, $printForm);

        $this->printFooter($order, $printForm);

        return $this->printService->generatePrintData(
            $device,
            $order->getProvider(),
            $aditionalData
        );
    }

    public function generateQueuePrintData(
        Order $order,
        Device $device,
        array $queueIds,
        ?array $aditionalData = []
    ): ?Spool {
        $queueBuckets = $this->getQueueBuckets($order, $queueIds);
        if (empty($queueBuckets)) {
            return null;
        }

        $printMode = $this->resolvePrintMode(
            $device,
            $order->getProvider(),
            $this->resolveAdditionalDataDeviceType($aditionalData)
        );
        $printForm = $printMode === 'form';

        $this->printProviderHeader($order->getProvider());
        $this->printOrderHeader($order, $printForm, true);
        $this->printOrderComments($order, $printForm);
        $this->printSeparator();
        $this->printQueueBuckets($queueBuckets, $printForm);
        $this->printQueueFooter($order, $printForm);

        return $this->printService->generatePrintData(
            $device,
            $order->getProvider(),
            $aditionalData
        );
    }

    public function generateQueueEntryPrintData(
        Order $order,
        Device $device,
        array $orderProductQueueIds,
        ?array $aditionalData = []
    ): ?Spool {
        $queueEntries = $this->getSelectedQueueEntries($order, $orderProductQueueIds);
        if (empty($queueEntries)) {
            return null;
        }

        $printMode = $this->resolvePrintMode(
            $device,
            $order->getProvider(),
            $this->resolveAdditionalDataDeviceType($aditionalData)
        );
        $printForm = $printMode === 'form';

        $this->printProviderHeader($order->getProvider());
        $this->printOrderHeader($order, $printForm, true);
        $this->printSeparator();
        $this->printQueueEntries($queueEntries, $printForm);
        $this->printQueueFooter($order, $printForm);

        return $this->printService->generatePrintData(
            $device,
            $order->getProvider(),
            $aditionalData
        );
    }

    public function generateOrderProductPrintData(
        OrderProduct $orderProduct,
        Device $device,
        array $orderProductQueueIds = [],
        ?array $aditionalData = []
    ): ?Spool {
        $order = $orderProduct->getOrder();
        if (!$order instanceof Order) {
            return null;
        }

        $selectedQueueEntries = !empty($orderProductQueueIds)
            ? $this->getSelectedQueueEntriesForOrderProduct(
                $orderProduct,
                $orderProductQueueIds
            )
            : [];

        if (!empty($orderProductQueueIds) && empty($selectedQueueEntries)) {
            return null;
        }

        $printMode = $this->resolvePrintMode(
            $device,
            $order->getProvider(),
            $this->resolveAdditionalDataDeviceType($aditionalData)
        );
        $printForm = $printMode === 'form';

        //$this->printProviderHeader($order->getProvider());
        $this->printOrderHeader($order, $printForm, true);
        $this->printSeparator();

        if (!empty($selectedQueueEntries)) {
            $this->printOrderProductQueueEntries($selectedQueueEntries, $printForm);
        } else {
            $this->printStandaloneOrderProduct($orderProduct, $printForm);
        }

        $this->printQueueFooter($order, $printForm);

        return $this->printService->generatePrintData(
            $device,
            $order->getProvider(),
            $aditionalData
        );
    }

    public function generateOrderProductPrintDataFromContent(
        OrderProduct $orderProduct,
        ?string $content
    ): ?Spool
    {
        return $this->generateOrderProductPrintDataFromPayload(
            $orderProduct,
            $this->decodePayload($content)
        );
    }

    public function generateOrderProductPrintDataFromPayload(
        OrderProduct $orderProduct,
        array $payload
    ): ?Spool
    {
        return $this->generateOrderProductPrintData(
            $orderProduct,
            $this->resolvePayloadDevice($payload),
            $this->normalizeIds($payload['orderProductQueueIds'] ?? [])
        );
    }

    public function generateOrderProductQueuePrintData(
        OrderProductQueue $orderProductQueue,
        Device $device,
        ?array $aditionalData = []
    ): ?Spool {
        $queueEntry = $this->normalizeQueueEntryForPrint($orderProductQueue);
        $orderProduct = $orderProductQueue->getOrderProduct();
        $order = $orderProduct?->getOrder();

        if ($queueEntry === null || !($order instanceof Order)) {
            return null;
        }

        $printMode = $this->resolvePrintMode(
            $device,
            $order->getProvider(),
            $this->resolveAdditionalDataDeviceType($aditionalData)
        );
        $printForm = $printMode === 'form';

        $this->printOrderHeader($order, $printForm, true);
        $this->printSeparator();
        $this->printOrderProductQueueEntries([$queueEntry], $printForm);
        $this->printQueueFooter($order, $printForm);

        return $this->printService->generatePrintData(
            $device,
            $order->getProvider(),
            $aditionalData
        );
    }

    public function generateOrderProductQueuePrintDataFromContent(
        OrderProductQueue $orderProductQueue,
        ?string $content
    ): ?Spool
    {
        return $this->generateOrderProductQueuePrintData(
            $orderProductQueue,
            $this->resolvePayloadDevice($this->decodePayload($content))
        );
    }

    private function resolvePayloadDevice(array $payload): Device
    {
        $deviceReference = trim((string) ($payload['device'] ?? ''));
        if ($deviceReference === '') {
            throw new BadRequestHttpException('Device not informed');
        }

        $device = $this->deviceService->resolveDeviceReference($deviceReference);
        if (!$device instanceof Device) {
            throw new NotFoundHttpException('Device not found');
        }

        return $device;
    }

    private function normalizeIds(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } elseif (trim($value) !== '') {
                $value = [$value];
            } else {
                $value = [];
            }
        } elseif (!is_array($value)) {
            $value = $value === null ? [] : [$value];
        }

        return array_values(array_filter(array_map(
            static fn($item) => trim((string) $item),
            $value
        )));
    }

    private function decodePayload(?string $content): array
    {
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function autoPrintOrderProductQueueEntry(
        OrderProductQueue $orderProductQueue
    ): int {
        $order = $orderProductQueue->getOrderProduct()?->getOrder();
        $provider = $order?->getProvider();

        if (
            !($order instanceof Order) ||
            !($provider instanceof People) ||
            !$orderProductQueue->getId()
        ) {
            return 0;
        }

        $printedCount = 0;
        foreach ($this->resolveAutoPrintDisplayTargets($orderProductQueue) as $target) {
            $printData = $this->generateOrderProductQueuePrintData(
                $orderProductQueue,
                $target['device'],
                [
                    'automaticProductPrint' => true,
                    'type' => $target['type'] ?? null,
                ]
            );

            if ($printData instanceof Spool) {
                $printedCount++;
            }
        }

        return $printedCount;
    }

    private function printProviderHeader(?People $provider): void
    {
        if ($provider === null) {
            return;
        }

        $providerName = $this->resolvePeopleName($provider);
        $providerAlias = trim($provider->getAlias());
        $providerDocument = $this->resolvePeopleDocument($provider);
        $providerPhone = $this->resolvePeoplePhone($provider);

        if ($providerName !== '') {
            $this->printService->addLine($providerName);
        }

        if ($providerAlias !== '' && $providerAlias !== $providerName) {
            $this->printService->addLine($providerAlias);
        }

        if ($providerDocument !== '') {
            $this->printService->addLine('DOC: ' . $providerDocument);
        }

        if ($providerPhone !== '') {
            $this->printService->addLine('TEL: ' . $providerPhone);
        }

        $this->printSeparator();
    }

    private function printOrderHeader(
        Order $order,
        bool $printForm,
        bool $highlightMarketplaceCode = false
    ): void {
        $app = trim((string) $order->getApp());
        $clientName = $order->getClient() ? $this->resolvePeopleName($order->getClient()) : 'NAO INFORMADO';
        $platformOrderCode = $this->resolveMarketplaceOrderCode($order);

        // Imrimir o número  do pedido da marketplace em destaque, caso a configuração de destaque esteja habilitada para o dispositivo e o código exista e caso não seja marketplace, imprimir o número do pedido local

        if ($platformOrderCode) {
            if ($highlightMarketplaceCode) {
                $this->printMarketplaceOrderHighlight($order, $platformOrderCode);
            } else {
                $this->printService->addLine(strtoupper($app) . ' - ' . $platformOrderCode);
            }
        } else {
            $this->printService->addLine('PEDIDO #' . $order->getId());
        }
        if (!$printForm) {
            $this->printService->addLine($clientName);
        }
    }

    private function printClientAddress(Order $order): void
    {
        $address = $this->resolveAddressDestinationDisplay($order);
        $this->printSeparator();

        if ($address === '') {
            $this->printService->addLine('ENTREGA: RETIRADA');
            return;
        }

        $this->printService->addLine('ENDERECO DO CLIENTE');
        $this->printWrappedBlock('', $address);
    }

    private function printMarketplaceHeader(Order $order): void
    {
        $app = strtolower(trim((string) $order->getApp()));

        if ($this->isIfoodMarketplaceApp($app)) {
            $this->printSeparator();
            $this->printService->addLine('DADOS IFOOD');
            $this->printMarketplaceBlock($this->getIfoodPrintData($order));
            return;
        }

        if ($this->isFood99MarketplaceApp($app)) {
            $this->printSeparator();
            $this->printService->addLine('DADOS 99');
            $this->printMarketplaceBlock($this->getFood99PrintData($order));
        }
    }

    private function printMarketplaceBlock(array $data): void
    {
        $labels = [
            'merchant_id' => 'LOJA',
            'pickup_code' => 'CODIGO DE COLETA',
            'handover_code' => 'CODIGO DE ENTREGA',
            'locator' => 'LOCALIZADOR',
            'virtual_phone' => 'TELEFONE VIRTUAL',
            'customer_phone' => 'TELEFONE CLIENTE',
            'selected_payment_label' => 'PAGAMENTO',
            'amount_paid' => 'VALOR PAGO',
            'amount_pending' => 'VALOR PENDENTE',
            'address_display' => 'ENDERECO',
            'remark' => 'OBSERVACAO',
            'delivered_by' => 'ENTREGA POR',
            'delivery_mode' => 'MODO ENTREGA',
        ];

        $printed = false;

        foreach ($labels as $field => $label) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '') {
                continue;
            }

            if (in_array($field, ['amount_paid', 'amount_pending'], true) && is_numeric($value)) {
                $value = $this->formatMoney((float) $value);
            }

            $this->printWrappedLabelValue($label, $value);
            $printed = true;
        }

        if (!$printed) {
            $this->printService->addLine('SEM DADOS COMPLEMENTARES');
        }
    }

    private function printOrderComments(Order $order, bool $printForm): void
    {
        if ($printForm) {
            return;
        }

        $comments = trim((string) $order->getComments());
        if ($comments === '') {
            return;
        }

        $this->printSeparator();
        $this->printService->addLine('OBSERVACOES DO PEDIDO');
        $this->printWrappedBlock('', $comments);
    }

    private function getGroups(Order $order): array
    {
        $groups = [];
        $sequence = 0;

        foreach ($order->getOrderProducts() as $orderProduct) {
            if ($orderProduct->getOrderProduct() !== null) {
                continue;
            }

            $groupOrder = 9999;
            $groupName = $this->resolveOrderProductGroupName(
                $orderProduct,
                $this->defaultGroupName,
                $groupOrder
            );

            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [
                    'name' => $groupName,
                    'groupOrder' => $groupOrder,
                    'sequence' => $sequence++,
                    'items' => [],
                ];
            }

            $groups[$groupName]['items'][] = $orderProduct;
        }

        return $this->sortGroupedItems($groups);
    }

    private function sortGroupedItems(array $groups): array
    {
        $groupList = array_values($groups);

        usort($groupList, function (array $left, array $right): int {
            if ($left['groupOrder'] === $right['groupOrder']) {
                return $left['sequence'] <=> $right['sequence'];
            }

            return $left['groupOrder'] <=> $right['groupOrder'];
        });

        return $groupList;
    }

    private function printGroups(array $groups, bool $printForm): void
    {
        foreach ($groups as $group) {
            $this->printService->addLine(strtoupper($group['name']));

            foreach ($group['items'] as $orderProduct) {
                if ($printForm) {
                    $this->printFormItem($orderProduct);
                    continue;
                }

                $this->printRegularItem($orderProduct);
            }

            $this->printService->addLine('', '', ' ');
        }
    }

    private function printQueueBuckets(array $queueBuckets, bool $printForm): void
    {
        foreach ($queueBuckets as $queueBucket) {
            $queueName = trim((string) ($queueBucket['name'] ?? ''));
            if ($queueName === '') {
                $queueName = $this->defaultQueueName;
            }
            foreach ($queueBucket['items'] as $orderProduct) {
                $this->printQueueItem($orderProduct, $printForm);
            }

            $this->printService->addLine('', '', ' ');
        }
    }

    private function printQueueEntries(array $queueEntries, bool $printForm): void
    {
        foreach ($queueEntries as $queueEntry) {
            $queueName = trim((string) ($queueEntry['queueName'] ?? ''));
            if ($queueName === '') {
                $queueName = $this->defaultQueueName;
            }

            $this->printQueueItem(
                $queueEntry['orderProduct'],
                $printForm,
                (float) ($queueEntry['quantity'] ?? 1)
            );
            $this->printService->addLine('', '', ' ');
        }
    }

    private function printOrderProductQueueEntries(
        array $queueEntries,
        bool $printForm
    ): void {
        foreach ($queueEntries as $queueEntry) {
            $queueName = trim((string) ($queueEntry['queueName'] ?? ''));
            if ($queueName === '') {
                $queueName = $this->defaultQueueName;
            }

            $this->printQueueItemWithCut(
                $queueEntry['orderProduct'],
                $printForm,
                (float) ($queueEntry['quantity'] ?? 1)
            );
        }
    }

    private function printStandaloneOrderProduct(
        OrderProduct $orderProduct,
        bool $printForm
    ): void {
        $queueName = $this->defaultQueueName;

        foreach ($orderProduct->getOrderProductQueues() as $queueEntry) {
            $queue = $queueEntry->getQueue();
            $resolvedQueueName = trim((string) ($queue?->getQueue() ?? ''));
            if ($resolvedQueueName !== '') {
                $queueName = $resolvedQueueName;
                break;
            }
        }

        $this->printQueueItemWithCut($orderProduct, $printForm);
    }

    private function printQueueItem(
        OrderProduct $orderProduct,
        bool $printForm,
        ?float $quantityOverride = null
    ): void
    {
        if ($printForm) {
            $this->printQueueFormItem($orderProduct, $quantityOverride);
            return;
        }

        $this->printQueueRegularItem($orderProduct, $quantityOverride);
    }

    private function printQueueItemWithCut(
        OrderProduct $orderProduct,
        bool $printForm,
        ?float $quantityOverride = null
    ): void {
        if ($printForm) {
            $this->printQueueFormItemWithCut($orderProduct, $quantityOverride);
            return;
        }

        $this->printQueueRegularItem($orderProduct, $quantityOverride);
        $this->printService->addCutMarker();
    }

    private function printQueueRegularItem(
        OrderProduct $orderProduct,
        ?float $quantityOverride = null
    ): void
    {
        $productName = $this->normalizeText($orderProduct->getProduct()->getProduct());
        $quantity = $quantityOverride ?? (float) $orderProduct->getQuantity();

        $this->printService->addLine(
            $this->formatQueueProductLine($productName, $quantity)
        );

        $this->printOrderProductDescription($orderProduct);
        $this->printOrderProductComment($orderProduct);
        $this->printChildren($orderProduct->getOrderProductComponents(), true);
        $this->printSeparator();
    }

    private function printQueueFormItem(
        OrderProduct $orderProduct,
        ?float $quantityOverride = null
    ): void
    {
        $productName = $this->normalizeText($orderProduct->getProduct()->getProduct());
        $quantity = (int) max(1, $quantityOverride ?? (float) $orderProduct->getQuantity());

        for ($i = 0; $i < $quantity; $i++) {
            $this->printService->addLine($this->formatQueueProductLine($productName, 1));
            $this->printOrderProductDescription($orderProduct);
            $this->printOrderProductComment($orderProduct);
            $this->printChildren($orderProduct->getOrderProductComponents(), true);
            $this->printSeparator();
        }
    }

    private function printQueueFormItemWithCut(
        OrderProduct $orderProduct,
        ?float $quantityOverride = null
    ): void
    {
        $productName = $this->normalizeText($orderProduct->getProduct()->getProduct());
        $quantity = (int) max(1, $quantityOverride ?? (float) $orderProduct->getQuantity());

        for ($i = 0; $i < $quantity; $i++) {
            $this->printService->addLine($this->formatQueueProductLine($productName, 1));
            $this->printOrderProductDescription($orderProduct);
            $this->printOrderProductComment($orderProduct);
            $this->printChildren($orderProduct->getOrderProductComponents(), true);
            $this->printSeparator();
            $this->printService->addCutMarker();
        }
    }

    private function printRegularItem(OrderProduct $orderProduct): void
    {
        $productName = $this->normalizeText($orderProduct->getProduct()->getProduct());

        $this->printService->addLine(
            $this->formatQuantity((float) $orderProduct->getQuantity()) . 'x ' . $productName,
            $this->formatMoney((float) $orderProduct->getTotal()),
            '.'
        );

        $this->printOrderProductComment($orderProduct);
        $this->printChildren($orderProduct->getOrderProductComponents());
        $this->printSeparator();
    }

    private function printFormItem(OrderProduct $orderProduct): void
    {
        $productName = $this->normalizeText($orderProduct->getProduct()->getProduct());
        $quantity = (int) max(1, (float) $orderProduct->getQuantity());

        for ($i = 0; $i < $quantity; $i++) {
            $this->printService->addLine(
                '1x ' . $productName,
                $this->formatMoney((float) $orderProduct->getPrice()),
                '.'
            );

            $this->printOrderProductComment($orderProduct);
            $this->printChildren($orderProduct->getOrderProductComponents());
            $this->printSeparator();
        }
    }

    private function printOrderProductDescription(OrderProduct $orderProduct): void
    {
        $productName = $this->normalizeText(
            (string) $orderProduct->getProduct()->getProduct()
        );
        $description = $this->normalizeText(
            (string) $orderProduct->getProduct()->getDescription()
        );

        if ($description === '' || $description === $productName) {
            return;
        }

        $this->printWrappedBlock('   DESC: ', $description);
    }

    private function printOrderProductComment(OrderProduct $orderProduct): void
    {
        $comment = trim((string) $orderProduct->getComment());
        if ($comment === '') {
            return;
        }

        $this->printWrappedBlock('   OBS: ', $comment);
    }

    private function printChildren(
        iterable $children,
        bool $includeHeader = false
    ): void {
        $groups = [];
        $sequence = 0;

        foreach ($children as $child) {
            $groupOrder = 9999;
            $groupName = $this->resolveOrderProductGroupName(
                $child,
                $this->defaultChildGroupName,
                $groupOrder
            );

            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [
                    'name' => $groupName,
                    'groupOrder' => $groupOrder,
                    'sequence' => $sequence++,
                    'items' => [],
                ];
            }

            $groups[$groupName]['items'][] = $child;
        }

        $groups = $this->sortGroupedItems($groups);

        if (empty($groups)) {
            return;
        }

        if ($includeHeader) {
            $this->printService->addLine('   COMPONENTES:');
        }

        foreach ($groups as $group) {
            if ($group['name'] !== $this->defaultChildGroupName) {
                $this->printService->addLine('  ' . strtoupper($group['name']) . ':');
            }

            foreach ($group['items'] as $child) {
                $line = $this->formatChildProductLine(
                    $this->normalizeText($child->getProduct()->getProduct()),
                    (float) $child->getQuantity()
                );

                $this->printWrappedBlock('   * ', $line);
                $this->printOrderProductComment($child);
            }
        }
    }

    private function printFooter(Order $order, bool $printForm): void
    {
        if (!$printForm) {
            $this->printService->addLine(
                'TOTAL',
                $this->formatMoney((float) $order->getPrice()),
                '.'
            );
        }

        $footerText = $this->resolvePrintFooterText($order->getProvider());
        if ($footerText !== '') {
            $this->printSeparator();
            $this->printWrappedMultilineBlock($footerText);
        }

        $this->printSeparator();
    }

    private function printQueueFooter(Order $order, bool $printForm): void
    {
        $footerText = $this->resolvePrintFooterText($order->getProvider());
        if ($footerText !== '') {
            $this->printSeparator();
            $this->printWrappedMultilineBlock($footerText);
        }

        $this->printSeparator();
    }

    private function printMarketplaceOrderHighlight(
        Order $order,
        ?string $platformOrderCode = null
    ): void {
        $platformOrderCode = trim((string) (
            $platformOrderCode ?: $this->resolveMarketplaceOrderCode($order)
        ));

        if ($platformOrderCode === '') {
            return;
        }

        $marketplaceLabel = $this->resolveMarketplaceAppLabel($order);

        $this->printSeparator('=');
        $this->printService->addLine($marketplaceLabel);
        $this->printWrappedBlock('', $platformOrderCode);
        $this->printSeparator('=');
    }

    private function getIfoodPrintData(Order $order): array
    {
        return [
            'code' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'code'),
            'merchant_id' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'merchant_id'),
            'pickup_code' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'pickup_code'),
            'handover_code' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'handover_code'),
            'locator' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'locator'),
            'virtual_phone' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'virtual_phone'),
            'customer_phone' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'customer_phone'),
            'selected_payment_label' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'selected_payment_label'),
            'amount_paid' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'amount_paid'),
            'amount_pending' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'amount_pending'),
            'address_display' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'address_display'),
            'remark' => $this->getMarketplaceRemark($order, [Order::APP_IFOOD]),
            'delivered_by' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'delivered_by'),
            'delivery_mode' => $this->getMarketplaceField($order, [Order::APP_IFOOD], 'delivery_mode'),
        ];
    }

    private function getFood99PrintData(Order $order): array
    {
        $contexts = [Order::APP_FOOD99];

        return [
            'code' => $this->getMarketplaceField($order, $contexts, 'code'),
            'merchant_id' => $this->getMarketplaceField($order, $contexts, 'merchant_id'),
            'pickup_code' => $this->getMarketplaceField($order, $contexts, 'pickup_code'),
            'handover_code' => $this->getMarketplaceField($order, $contexts, 'handover_code'),
            'locator' => $this->getMarketplaceField($order, $contexts, 'locator'),
            'virtual_phone' => $this->getMarketplaceField($order, $contexts, 'virtual_phone'),
            'customer_phone' => $this->getMarketplaceField($order, $contexts, 'customer_phone'),
            'selected_payment_label' => $this->getMarketplaceField($order, $contexts, 'selected_payment_label'),
            'amount_paid' => $this->getMarketplaceField($order, $contexts, 'amount_paid'),
            'amount_pending' => $this->getMarketplaceField($order, $contexts, 'amount_pending'),
            'address_display' => $this->getMarketplaceField($order, $contexts, 'address_display'),
            'remark' => $this->getMarketplaceRemark($order, $contexts),
            'delivered_by' => $this->getMarketplaceField($order, $contexts, 'delivered_by'),
            'delivery_mode' => $this->getMarketplaceField($order, $contexts, 'delivery_mode'),
        ];
    }

    private function resolveMarketplaceOrderCode(Order $order): string
    {
        $app = strtolower(trim((string) $order->getApp()));

        if ($this->isIfoodMarketplaceApp($app)) {
            return trim(
                $this->getMarketplaceField($order, [Order::APP_IFOOD], 'code')
                    ?: $this->getMarketplaceField($order, [Order::APP_IFOOD], 'id')
            );
        }

        if ($this->isFood99MarketplaceApp($app)) {
            return trim(
                $this->getMarketplaceField($order, [Order::APP_FOOD99], 'code')
                    ?: $this->getMarketplaceField($order, [Order::APP_FOOD99], 'id')
            );
        }

        return '';
    }

    private function resolveMarketplaceAppLabel(Order $order): string
    {
        $app = strtolower(trim((string) $order->getApp()));

        if ($this->isIfoodMarketplaceApp($app)) {
            return 'IFOOD';
        }

        if ($this->isFood99MarketplaceApp($app)) {
            return '99';
        }

        return strtoupper(trim((string) $order->getApp()));
    }

    private function isIfoodMarketplaceApp(string $app): bool
    {
        return $app === strtolower(Order::APP_IFOOD);
    }

    private function isFood99MarketplaceApp(string $app): bool
    {
        return $app === strtolower(Order::APP_FOOD99);
    }

    private function getMarketplaceRemark(Order $order, array $contexts): string
    {
        $remark = trim($this->getMarketplaceField($order, $contexts, 'remark'));
        if ($remark === '') {
            return '';
        }

        $comments = trim((string) $order->getComments());
        if ($comments === '') {
            return $remark;
        }

        return $this->normalizeText($remark) === $this->normalizeText($comments)
            ? ''
            : $remark;
    }

    private function getMarketplaceField(Order $order, array $contexts, string $fieldName): string
    {
        $extraDataMap = $this->getExtraDataMap($order);

        foreach ($contexts as $context) {
            $normalizedContext = strtolower(trim($context));
            $value = trim((string) ($extraDataMap[$normalizedContext][$fieldName] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach ($contexts as $context) {
            $contextData = $this->getContextFromOtherInformations($order, $context);
            $value = trim((string) ($contextData[$fieldName] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function getExtraDataMap(Order $order): array
    {
        $orderId = (int) $order->getId();

        if (isset($this->extraDataCache[$orderId])) {
            return $this->extraDataCache[$orderId];
        }

        $map = [];
        $extraDataList = $this->extraDataService->getExtraDataFromEntity($order);

        foreach ($extraDataList as $extraData) {
            $extraFields = $extraData->getExtraFields();
            if ($extraFields === null) {
                continue;
            }

            $context = strtolower(trim((string) $extraFields->getContext()));
            $name = trim((string) $extraFields->getName());
            $value = trim((string) $extraData->getValue());

            if ($context === '' || $name === '') {
                continue;
            }

            if (!isset($map[$context])) {
                $map[$context] = [];
            }

            $map[$context][$name] = $value;
        }

        $this->extraDataCache[$orderId] = $map;

        return $map;
    }

    private function getContextFromOtherInformations(Order $order, string $context): array
    {
        $otherInformations = $order->getOtherInformations(true);
        $otherInformations = json_decode(json_encode($otherInformations), true);

        if (!is_array($otherInformations)) {
            return [];
        }

        foreach ($otherInformations as $key => $value) {
            if (strtolower((string) $key) !== strtolower($context)) {
                continue;
            }

            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    private function resolvePeopleName(People $people): string
    {
        $name = trim($people->getName());
        if ($name !== '') {
            return $name;
        }

        return trim($people->getAlias());
    }

    private function resolvePeopleDocument(People $people): string
    {
        $document = $people->getOneDocument();

        if (!$document instanceof Document) {
            return '';
        }

        return trim($document->getDocument());
    }

    private function resolvePeoplePhone(People $people): string
    {
        $phone = $people->getPhone()->first();

        if ($phone === false) {
            return '';
        }

        return $this->formatPhone($phone);
    }

    private function formatPhone(Phone $phone): string
    {
        return '+' . $phone->getDdi() . ' (' . $phone->getDdd() . ') ' . $phone->getPhone();
    }

    private function resolveAddressDestinationDisplay(Order $order): string
    {
        $address = $order->getAddressDestination();
        if (!$address instanceof Address) {
            return '';
        }

        return $this->formatAddressDestination($address);
    }

    private function formatAddressDestination(Address $address): string
    {
        $street = $address->getStreet();
        if ($street === null) {
            return '';
        }

        $district = $street->getDistrict();
        $city = $district?->getCity();
        $state = $city?->getState();
        $cep = $street->getCep();

        $streetName = trim((string) $street->getStreet());
        $streetNumber = trim((string) $address->getNumber());
        $districtName = trim((string) ($district?->getDistrict() ?? ''));
        $cityName = trim((string) ($city?->getCity() ?? ''));
        $stateUf = trim((string) ($state?->getUf() ?? ''));
        $postalCode = trim((string) ($cep?->getCep() ?? ''));
        $complement = trim((string) $address->getComplement());

        $parts = [];

        $mainLine = $streetName;
        if ($streetNumber !== '') {
            $mainLine .= ', ' . $streetNumber;
        }
        if ($districtName !== '') {
            $mainLine .= ' - ' . $districtName;
        }
        if ($mainLine !== '') {
            $parts[] = $mainLine;
        }

        $regionLine = $cityName;
        if ($stateUf !== '') {
            $regionLine .= $regionLine !== '' ? '/' . $stateUf : $stateUf;
        }
        if ($postalCode !== '') {
            $regionLine .= $regionLine !== '' ? ' - CEP ' . $postalCode : 'CEP ' . $postalCode;
        }
        if ($regionLine !== '') {
            $parts[] = $regionLine;
        }

        if ($complement !== '') {
            $parts[] = 'COMP: ' . $complement;
        }

        return $this->normalizeText(implode(' | ', $parts));
    }

    private function resolvePrintFooterText(?People $provider): string
    {
        if (!$provider instanceof People) {
            return '';
        }

        $value = $this->configService->getConfig($provider, 'order-print-footer-text');
        if ($value === null || $value === '') {
            return '';
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return '';
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
                return $decoded;
            }
        }

        return (string) $value;
    }

    private function printDisplayCopies(Order $order, array $aditionalData = []): void
    {
        foreach ($this->resolveDisplayPrintTargets($order) as $target) {
            $this->generateQueuePrintData(
                $order,
                $target['device'],
                $target['queueIds'],
                array_merge(
                    $aditionalData,
                    !empty($target['type']) ? ['type' => $target['type']] : []
                )
            );
        }
    }

    private function resolveDisplayPrintTargets(Order $order): array
    {
        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return [];
        }

        $orderDisplays = $this->getOrderDisplays($order);
        if (empty($orderDisplays)) {
            return [];
        }

        $targets = [];
        $deviceConfigs = $this->manager->getRepository(DeviceConfig::class)->findBy([
            'people' => $provider,
        ]);

        foreach ($deviceConfigs as $deviceConfig) {
            $device = $deviceConfig->getDevice();
            if (!$this->isDisplayDevice($deviceConfig)) {
                continue;
            }

            $configs = $deviceConfig->getConfigs(true);
            if (!is_array($configs)) {
                continue;
            }

            $displayId = $this->normalizeEntityId($configs[$this->displayConfigKey] ?? null);
            if ($displayId === null || !isset($orderDisplays[$displayId])) {
                continue;
            }

            if (trim((string) ($configs[$this->printerConfigKey] ?? '')) === '') {
                continue;
            }

            $deviceId = $device->getId();
            $queueIds = $orderDisplays[$displayId]['queueIds'];

            if (!isset($targets[$deviceId])) {
                $targets[$deviceId] = [
                    'device' => $device,
                    'type' => strtoupper(trim((string) $deviceConfig->getType())),
                    'queueIds' => [],
                ];
            }

            $targets[$deviceId]['queueIds'] = array_values(array_unique(array_merge(
                $targets[$deviceId]['queueIds'],
                $queueIds
            )));
        }

        return array_values($targets);
    }

    private function resolveAutoPrintDisplayTargets(
        OrderProductQueue $orderProductQueue
    ): array {
        $orderProduct = $orderProductQueue->getOrderProduct();
        $order = $orderProduct?->getOrder();
        $provider = $order?->getProvider();
        $queue = $orderProductQueue->getQueue();

        if (!$provider instanceof People || $queue === null) {
            return [];
        }

        $displayRows = $this->manager->getRepository(DisplayQueue::class)->findBy([
            'queue' => $queue,
        ]);

        if (empty($displayRows)) {
            return [];
        }

        $displayIds = [];
        foreach ($displayRows as $displayRow) {
            $displayId = $this->normalizeEntityId($displayRow->getDisplay()?->getId());
            if ($displayId !== null) {
                $displayIds[$displayId] = $displayId;
            }
        }

        if (empty($displayIds)) {
            return [];
        }

        $targets = [];
        $deviceConfigs = $this->manager->getRepository(DeviceConfig::class)->findBy([
            'people' => $provider,
        ]);

        foreach ($deviceConfigs as $deviceConfig) {
            $device = $deviceConfig->getDevice();
            if (!$this->isDisplayDevice($deviceConfig)) {
                continue;
            }

            $configs = $deviceConfig->getConfigs(true);
            if (!is_array($configs)) {
                continue;
            }

            $displayId = $this->normalizeEntityId($configs[$this->displayConfigKey] ?? null);
            if ($displayId === null || !isset($displayIds[$displayId])) {
                continue;
            }

            if (!$this->isTruthyConfigValue(
                $configs[$this->displayAutoPrintProductConfigKey] ?? null
            )) {
                continue;
            }

            if (trim((string) ($configs[$this->printerConfigKey] ?? '')) === '') {
                continue;
            }

            $deviceId = $device->getId();
            if (isset($targets[$deviceId])) {
                continue;
            }

            $targets[$deviceId] = [
                'device' => $device,
                'displayId' => $displayId,
                'type' => strtoupper(trim((string) $deviceConfig->getType())),
            ];
        }

        return array_values($targets);
    }

    private function getOrderDisplays(Order $order): array
    {
        $queueBuckets = $this->getQueueBuckets($order);
        if (empty($queueBuckets)) {
            return [];
        }

        $queues = [];
        foreach ($queueBuckets as $queueBucket) {
            $queue = $queueBucket['queue'] ?? null;
            $queueId = $this->normalizeEntityId($queue?->getId());

            if ($queue === null || $queueId === null || $queueId < 1) {
                continue;
            }

            $queues[$queueId] = $queue;
        }

        if (empty($queues)) {
            return [];
        }

        $displayRows = $this->manager->getRepository(DisplayQueue::class)->findBy([
            'queue' => array_values($queues),
        ]);

        $displays = [];
        foreach ($displayRows as $displayRow) {
            $display = $displayRow->getDisplay();
            $displayId = $this->normalizeEntityId($display?->getId());
            $queueId = $this->normalizeEntityId($displayRow->getQueue()?->getId());

            if ($displayId === null || $queueId === null || !isset($queues[$queueId])) {
                continue;
            }

            if (!isset($displays[$displayId])) {
                $displays[$displayId] = [
                    'display' => $display,
                    'queueIds' => [],
                ];
            }

            $displays[$displayId]['queueIds'][$queueId] = $queueId;
        }

        foreach ($displays as &$displayData) {
            $displayData['queueIds'] = array_values($displayData['queueIds']);
        }

        return $displays;
    }

    private function getQueueBuckets(
        Order $order,
        array $allowedQueueIds = [],
        array $allowedOrderProductQueueIds = []
    ): array {
        $allowedQueueMap = $this->normalizeAllowedIds($allowedQueueIds);
        $allowedOrderProductQueueMap = $this->normalizeAllowedIds(
            $allowedOrderProductQueueIds
        );
        $shouldFilterByQueue = !empty($allowedQueueMap);
        $shouldFilterByOrderProductQueue = !empty($allowedOrderProductQueueMap);
        $queueBuckets = [];
        $sequence = 0;

        foreach ($order->getOrderProducts() as $orderProduct) {
            if ($orderProduct->getOrderProduct() !== null) {
                continue;
            }

            $queueEntries = $orderProduct->getOrderProductQueues();
            if ($queueEntries->isEmpty()) {
                if ($shouldFilterByQueue || $shouldFilterByOrderProductQueue) {
                    continue;
                }

                $this->addOrderProductToQueueBucket(
                    $queueBuckets,
                    'queue-0',
                    0,
                    $this->defaultQueueName,
                    $orderProduct,
                    $sequence,
                    null
                );
                continue;
            }

            foreach ($queueEntries as $queueEntry) {
                $queueEntryId = $this->normalizeEntityId($queueEntry?->getId()) ?? 0;
                if (
                    $shouldFilterByOrderProductQueue &&
                    !isset($allowedOrderProductQueueMap[$queueEntryId])
                ) {
                    continue;
                }

                $queue = $queueEntry->getQueue();
                $queueId = $this->normalizeEntityId($queue?->getId()) ?? 0;

                if ($shouldFilterByQueue && !isset($allowedQueueMap[$queueId])) {
                    continue;
                }

                $queueName = trim((string) ($queue?->getQueue() ?? ''));
                if ($queueName === '') {
                    $queueName = $this->defaultQueueName;
                }

                $this->addOrderProductToQueueBucket(
                    $queueBuckets,
                    'queue-' . $queueId,
                    $queueId,
                    $queueName,
                    $orderProduct,
                    $sequence,
                    $queue
                );
            }
        }

        foreach ($queueBuckets as &$queueBucket) {
            unset($queueBucket['seen']);
        }

        return array_values($queueBuckets);
    }

    private function getSelectedQueueEntries(
        Order $order,
        array $allowedOrderProductQueueIds = []
    ): array {
        $allowedOrderProductQueueMap = $this->normalizeAllowedIds(
            $allowedOrderProductQueueIds
        );

        if (empty($allowedOrderProductQueueMap)) {
            return [];
        }

        $selectedEntries = [];

        foreach ($order->getOrderProducts() as $orderProduct) {
            if ($orderProduct->getOrderProduct() !== null) {
                continue;
            }

            foreach ($orderProduct->getOrderProductQueues() as $queueEntry) {
                $queueEntryId = $this->normalizeEntityId($queueEntry?->getId());
                if (
                    $queueEntryId === null ||
                    !isset($allowedOrderProductQueueMap[$queueEntryId])
                ) {
                    continue;
                }

                $normalizedQueueEntry = $this->normalizeQueueEntryForPrint($queueEntry);
                if ($normalizedQueueEntry === null) {
                    continue;
                }

                $selectedEntries[$queueEntryId] = $normalizedQueueEntry;
            }
        }

        ksort($selectedEntries);

        return array_values($selectedEntries);
    }

    private function getSelectedQueueEntriesForOrderProduct(
        OrderProduct $orderProduct,
        array $allowedOrderProductQueueIds = []
    ): array {
        $allowedOrderProductQueueMap = $this->normalizeAllowedIds(
            $allowedOrderProductQueueIds
        );

        if (empty($allowedOrderProductQueueMap)) {
            return [];
        }

        $selectedEntries = [];

        foreach ($orderProduct->getOrderProductQueues() as $queueEntry) {
            $queueEntryId = $this->normalizeEntityId($queueEntry?->getId());
            if (
                $queueEntryId === null ||
                !isset($allowedOrderProductQueueMap[$queueEntryId])
            ) {
                continue;
            }

            $normalizedQueueEntry = $this->normalizeQueueEntryForPrint($queueEntry);
            if ($normalizedQueueEntry === null) {
                continue;
            }

            $selectedEntries[$queueEntryId] = $normalizedQueueEntry;
        }

        ksort($selectedEntries);

        return array_values($selectedEntries);
    }

    private function addOrderProductToQueueBucket(
        array &$queueBuckets,
        string $bucketKey,
        int $queueId,
        string $queueName,
        OrderProduct $orderProduct,
        int &$sequence,
        mixed $queue
    ): void {
        if (!isset($queueBuckets[$bucketKey])) {
            $queueBuckets[$bucketKey] = [
                'id' => $queueId,
                'name' => $queueName,
                'queue' => $queue,
                'sequence' => $sequence++,
                'items' => [],
                'seen' => [],
            ];
        }

        $orderProductId = (int) $orderProduct->getId();
        if ($orderProductId > 0 && isset($queueBuckets[$bucketKey]['seen'][$orderProductId])) {
            return;
        }

        $queueBuckets[$bucketKey]['items'][] = $orderProduct;

        if ($orderProductId > 0) {
            $queueBuckets[$bucketKey]['seen'][$orderProductId] = true;
        }
    }

    private function normalizeQueueEntryForPrint(
        OrderProductQueue $queueEntry
    ): ?array {
        $queueEntryId = $this->normalizeEntityId($queueEntry->getId());
        $orderProduct = $queueEntry->getOrderProduct();

        if ($queueEntryId === null || !($orderProduct instanceof OrderProduct)) {
            return null;
        }

        $queue = $queueEntry->getQueue();
        $queueName = trim((string) ($queue?->getQueue() ?? ''));

        return [
            'id' => $queueEntryId,
            'queue' => $queue,
            'queueName' => $queueName === ''
                ? $this->defaultQueueName
                : $queueName,
            'quantity' => 1.0,
            'orderProduct' => $orderProduct,
            'orderProductQueue' => $queueEntry,
        ];
    }

    private function normalizeAllowedIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            $normalizedId = $this->normalizeEntityId($id);
            if ($normalizedId === null) {
                continue;
            }

            $normalized[$normalizedId] = true;
        }

        return $normalized;
    }

    private function isTruthyConfigValue(mixed $value): bool
    {
        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    private function normalizeEntityId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value)) {
            $digits = preg_replace('/\D+/', '', $value);
            if ($digits === '') {
                return null;
            }

            $normalized = (int) $digits;
            return $normalized > 0 ? $normalized : null;
        }

        if (is_object($value) && method_exists($value, 'getId')) {
            return $this->normalizeEntityId($value->getId());
        }

        return null;
    }

    private function isDisplayDevice(?DeviceConfig $deviceConfig): bool
    {
        return strtoupper(trim((string) $deviceConfig?->getType())) === $this->displayDeviceType;
    }

    private function resolveOrderProductGroupName(
        OrderProduct $orderProduct,
        string $fallbackName,
        int &$groupOrder
    ): string {
        $productGroup = $orderProduct->getProductGroup();

        if ($productGroup === null) {
            $groupOrder = 9999;
            return $fallbackName;
        }

        $groupOrder = (int) $productGroup->getGroupOrder();

        $groupName = trim($productGroup->getProductGroup());
        if ($groupName === '') {
            return $fallbackName;
        }

        return $groupName;
    }

    private function printSeparator(string $delimiter = '-'): void
    {
        $this->printService->addLine('', '', $delimiter);
    }

    private function printWrappedLabelValue(string $label, string $value): void
    {
        $prefix = $label . ': ';
        $nextPrefix = str_repeat(' ', strlen($prefix));

        $this->printWrappedBlock($prefix, $value, $nextPrefix);
    }

    private function printWrappedMultilineBlock(string $text): void
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $text) ?: [];

        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                $this->printService->addLine('');
                continue;
            }

            $this->printWrappedBlock('', $line);
        }
    }

    private function printWrappedBlock(string $firstPrefix, string $text, ?string $nextPrefix = null): void
    {
        $text = $this->normalizeText($text);
        $nextPrefix = $nextPrefix ?? str_repeat(' ', strlen($firstPrefix));

        if ($text === '') {
            $this->printService->addLine(rtrim($firstPrefix));
            return;
        }

        $firstWidth = $this->contentWidth - strlen($firstPrefix);
        $nextWidth = $this->contentWidth - strlen($nextPrefix);

        if ($firstWidth < 5) {
            $firstWidth = 5;
        }

        if ($nextWidth < 5) {
            $nextWidth = 5;
        }

        $lines = $this->wrapText($text, $firstWidth, $nextWidth);

        if (empty($lines)) {
            $this->printService->addLine(rtrim($firstPrefix));
            return;
        }

        $firstLine = array_shift($lines);
        $this->printService->addLine($firstPrefix . $firstLine);

        foreach ($lines as $line) {
            $this->printService->addLine($nextPrefix . $line);
        }
    }

    private function wrapText(string $text, int $firstWidth, int $nextWidth): array
    {
        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $current = '';
        $limit = $firstWidth;

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if (strlen($candidate) <= $limit) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                $current = '';
                $limit = $nextWidth;
            }

            while (strlen($word) > $limit) {
                $lines[] = substr($word, 0, $limit);
                $word = substr($word, $limit);
                $limit = $nextWidth;
            }

            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function formatQuantity(float $quantity): string
    {
        $formatted = number_format($quantity, 2, ',', '.');
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, ',');

        return $formatted === '' ? '0' : $formatted;
    }

    private function formatQueueProductLine(string $productName, float $quantity): string
    {
        if ($quantity < 2) {
            return $productName;
        }

        return $this->formatQuantity($quantity) . 'x ' . $productName;
    }

    private function formatChildProductLine(string $productName, float $quantity): string
    {
        if ($quantity < 2) {
            return $productName;
        }

        return $this->formatQuantity($quantity) . 'x ' . $productName;
    }

    private function formatMoney(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
