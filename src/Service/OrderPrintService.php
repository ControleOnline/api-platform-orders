<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Document;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Phone;
use ControleOnline\Entity\Spool;
use Doctrine\ORM\EntityManagerInterface;

class OrderPrintService
{
    private string $defaultGroupName = 'ITENS';
    private string $defaultChildGroupName = 'OBSERVACOES';
    private int $contentWidth = 40;
    private array $extraDataCache = [];

    public function __construct(
        private EntityManagerInterface $manager,
        private PrintService $printService,
        private ConfigService $configService,
        private DeviceService $deviceService,
        private ExtraDataService $extraDataService,
    ) {}

    public function printOrder(Order $order, ?array $devices = [], ?array $aditionalData = []): void
    {
        if (empty($devices)) {
            $devices = $this->configService->getConfig(
                $order->getProvider(),
                'order-print-devices',
                true
            );
        }

        if (!$devices) {
            return;
        }

        $devices = $this->deviceService->findDevices($devices);

        foreach ($devices as $device) {
            $this->generatePrintData($order, $device, $aditionalData);
        }
    }

    public function generatePrintData(Order $order, Device $device, ?array $aditionalData = []): Spool
    {
        $deviceConfigs = $this->manager->getRepository(DeviceConfig::class)->findOneBy([
            'device' => $device->getId(),
        ]);

        $printMode = $deviceConfigs?->getConfigs(true)['print-mode'] ?? 'order';
        $printForm = $printMode === 'form';

        $this->printProviderHeader($order->getProvider());
        $this->printOrderHeader($order, $printForm);
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

    private function printOrderHeader(Order $order, bool $printForm): void
    {
        $app = trim((string) $order->getApp());
        $orderType = trim((string) $order->getOrderType());
        $clientName = $order->getClient() ? $this->resolvePeopleName($order->getClient()) : 'NAO INFORMADO';

        $this->printService->addLine('PEDIDO #' . $order->getId());
        $this->printService->addLine($order->getOrderDate()->format('d/m/Y H:i'));

        if ($app !== '') {
            $this->printService->addLine('APP: ' . strtoupper($app));
        }

        if ($orderType !== '') {
            $this->printService->addLine('TIPO: ' . strtoupper($orderType));
        }

        if (!$printForm) {
            $this->printService->addLine('CLIENTE: ' . $clientName);
        }
    }

    private function printMarketplaceHeader(Order $order): void
    {
        $app = strtolower(trim((string) $order->getApp()));

        if ($app === 'ifood') {
            $this->printSeparator();
            $this->printService->addLine('DADOS IFOOD');
            $this->printMarketplaceBlock($this->getIfoodPrintData($order));
            return;
        }

        if (in_array($app, ['99', '99food', '99 food'], true)) {
            $this->printSeparator();
            $this->printService->addLine('DADOS 99');
            $this->printMarketplaceBlock($this->get99PrintData($order));
        }
    }

    private function printMarketplaceBlock(array $data): void
    {
        $labels = [
            'code' => 'CODIGO',
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

    private function printOrderProductComment(OrderProduct $orderProduct): void
    {
        $comment = trim((string) $orderProduct->getComment());
        if ($comment === '') {
            return;
        }

        $this->printWrappedBlock('   OBS: ', $comment);
    }

    private function printChildren(iterable $children): void
    {
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

        foreach ($groups as $group) {
            if ($group['name'] !== $this->defaultChildGroupName) {
                $this->printService->addLine('  ' . strtoupper($group['name']) . ':');
            }

            foreach ($group['items'] as $child) {
                $line = $this->formatQuantity((float) $child->getQuantity()) . 'x ' .
                    $this->normalizeText($child->getProduct()->getProduct());

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

        $this->printSeparator();
    }

    private function getIfoodPrintData(Order $order): array
    {
        return [
            'code' => $this->getMarketplaceField($order, ['ifood'], 'code'),
            'merchant_id' => $this->getMarketplaceField($order, ['ifood'], 'merchant_id'),
            'pickup_code' => $this->getMarketplaceField($order, ['ifood'], 'pickup_code'),
            'handover_code' => $this->getMarketplaceField($order, ['ifood'], 'handover_code'),
            'locator' => $this->getMarketplaceField($order, ['ifood'], 'locator'),
            'virtual_phone' => $this->getMarketplaceField($order, ['ifood'], 'virtual_phone'),
            'customer_phone' => $this->getMarketplaceField($order, ['ifood'], 'customer_phone'),
            'selected_payment_label' => $this->getMarketplaceField($order, ['ifood'], 'selected_payment_label'),
            'amount_paid' => $this->getMarketplaceField($order, ['ifood'], 'amount_paid'),
            'amount_pending' => $this->getMarketplaceField($order, ['ifood'], 'amount_pending'),
            'address_display' => $this->getMarketplaceField($order, ['ifood'], 'address_display'),
            'remark' => $this->getMarketplaceField($order, ['ifood'], 'remark'),
            'delivered_by' => $this->getMarketplaceField($order, ['ifood'], 'delivered_by'),
            'delivery_mode' => $this->getMarketplaceField($order, ['ifood'], 'delivery_mode'),
        ];
    }

    private function get99PrintData(Order $order): array
    {
        $contexts = ['99', '99food'];

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
            'remark' => $this->getMarketplaceField($order, $contexts, 'remark'),
            'delivered_by' => $this->getMarketplaceField($order, $contexts, 'delivered_by'),
            'delivery_mode' => $this->getMarketplaceField($order, $contexts, 'delivery_mode'),
        ];
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

    private function printSeparator(): void
    {
        $this->printService->addLine('', '', '-');
    }

    private function printWrappedLabelValue(string $label, string $value): void
    {
        $prefix = $label . ': ';
        $nextPrefix = str_repeat(' ', strlen($prefix));

        $this->printWrappedBlock($prefix, $value, $nextPrefix);
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
