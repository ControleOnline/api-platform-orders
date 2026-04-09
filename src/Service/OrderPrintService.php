<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\Spool;
use Doctrine\ORM\EntityManagerInterface;

class OrderPrintService
{
    private string $defaultGroupName = 'ITENS';
    private string $defaultChildGroupName = 'OBSERVACOES';
    private array $extraDataCache = [];

    public function __construct(
        private EntityManagerInterface $manager,
        private PrintService $printService,
        private ConfigService $configService,
        private DeviceService $deviceService,
        private ExtraDataService $extraDataService,
    ) {}

    public function printOrder(Order $order, ?array $devices = []): void
    {
        if (empty($devices)) {
            $devices = $this->configService->getConfig(
                $order->getProvider(),
                'order-print-devices',
                true
            );
        }

        if ($devices) {
            $devices = $this->deviceService->findDevices($devices);
        }

        foreach ($devices as $device) {
            $this->generatePrintData($order, $device);
        }
    }

    public function generatePrintData(Order $order, Device $device, ?array $aditionalData = []): Spool
    {
        if (method_exists($this->printService, 'reset')) {
            $this->printService->reset();
        }

        $deviceConfigs = $this->manager->getRepository(DeviceConfig::class)->findOneBy([
            'device' => $device->getId(),
        ]);

        $printMode = $deviceConfigs?->getConfigs(true)['print-mode'] ?? 'order';
        $printForm = $printMode === 'form';

        $this->printProviderHeader($order);
        $this->printOrderHeader($order, $printForm);
        $this->printMarketplaceHeader($order);
        $this->printOrderComments($order, $printForm);
        $this->printSeparator();

        $groups = $this->getProductGroups($order);
        $this->printGroups($groups, $printForm);

        $this->printFooter($order, $printForm);

        return $this->printService->generatePrintData(
            $device,
            $order->getProvider(),
            $aditionalData
        );
    }

    private function printProviderHeader(Order $order): void
    {
        $provider = $order->getProvider();
        if (!$provider) {
            return;
        }

        $providerName = $this->resolvePeopleName($provider);
        $providerDocument = $this->resolvePeopleDocument($provider);
        $providerPhone = $this->resolvePeoplePhone($provider);

        if ($providerName !== '') {
            $this->printService->addLine($this->upper($providerName));
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
        $app = $this->normalizeText((string) ($order->getApp() ?? 'POS'));
        $orderType = $this->normalizeText((string) ($order->getOrderType() ?? ''));
        $clientName = $this->resolvePeopleName($order->getClient());

        $this->printService->addLine('PEDIDO #' . $order->getId());
        $this->printService->addLine($order->getOrderDate()->format('d/m/Y H:i'));

        if ($app !== '') {
            $this->printService->addLine('APP: ' . $this->upper($app));
        }

        if ($orderType !== '') {
            $this->printService->addLine('TIPO: ' . $this->upper($orderType));
        }

        if (!$printForm) {
            $this->printService->addLine('CLIENTE: ' . ($clientName ?: 'NAO INFORMADO'));
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
                $value = 'R$ ' . number_format((float) $value, 2, ',', '.');
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

        $comments = trim((string) ($order->getComments() ?? ''));
        if ($comments === '') {
            return;
        }

        $this->printSeparator();
        $this->printService->addLine('OBSERVACOES DO PEDIDO');
        $this->printWrappedBlock('', $comments);
    }

    private function getProductGroups(Order $order): array
    {
        $groups = [];

        foreach ($order->getOrderProducts() as $orderProduct) {
            if ($orderProduct->getOrderProduct() !== null) {
                continue;
            }

            $groupName = $this->resolveParentGroupName($orderProduct);
            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [];
            }

            $groups[$groupName][] = $orderProduct;
        }

        return $groups;
    }

    private function printGroups(array $groups, bool $printForm): void
    {
        foreach ($groups as $groupName => $orderProducts) {
            $this->printService->addLine($this->upper($groupName));

            foreach ($orderProducts as $orderProduct) {
                if ($printForm) {
                    $this->printFormItem($orderProduct);
                    continue;
                }

                $this->printRegularItem($orderProduct);
            }

            $this->printService->addLine('', '', ' ');
        }
    }

    private function printRegularItem(object $orderProduct): void
    {
        $product = $orderProduct->getProduct();
        $name = $this->normalizeText((string) $product->getProduct());

        $this->printService->addLine(
            (int) $orderProduct->getQuantity() . 'x ' . $name,
            'R$ ' . number_format((float) $orderProduct->getTotal(), 2, ',', '.'),
            '.'
        );

        $this->printChildren($orderProduct->getOrderProductComponents());
        $this->printSeparator();
    }

    private function printFormItem(object $orderProduct): void
    {
        $product = $orderProduct->getProduct();
        $name = $this->normalizeText((string) $product->getProduct());
        $quantity = max(1, (int) $orderProduct->getQuantity());

        for ($i = 0; $i < $quantity; $i++) {
            $this->printService->addLine(
                '1x ' . $name,
                'R$ ' . number_format((float) $orderProduct->getPrice(), 2, ',', '.'),
                '.'
            );

            $this->printChildren($orderProduct->getOrderProductComponents());
            $this->printSeparator();
        }
    }

    private function printChildren(iterable $children): void
    {
        $groupedChildren = [];

        foreach ($children as $child) {
            $groupName = $this->resolveChildGroupName($child);
            if (!isset($groupedChildren[$groupName])) {
                $groupedChildren[$groupName] = [];
            }

            $groupedChildren[$groupName][] = $child;
        }

        foreach ($groupedChildren as $groupName => $items) {
            if ($groupName !== $this->defaultChildGroupName) {
                $this->printService->addLine('  ' . $this->upper($groupName) . ':');
            }

            foreach ($items as $item) {
                $itemName = $this->normalizeText((string) $item->getProduct()->getProduct());
                $quantity = method_exists($item, 'getQuantity') ? (int) $item->getQuantity() : 1;

                $prefix = $quantity > 1
                    ? '   * ' . $quantity . 'x '
                    : '   * ';

                $this->printWrappedBlock($prefix, $itemName);
            }
        }
    }

    private function printFooter(Order $order, bool $printForm): void
    {
        if (!$printForm) {
            $this->printService->addLine(
                'TOTAL',
                'R$ ' . number_format((float) $order->getPrice(), 2, ',', '.'),
                '.'
            );
        }

        $this->printSeparator();
    }

    private function getIfoodPrintData(Order $order): array
    {
        return [
            'code' => $this->getExtraDataValue($order, ['ifood'], 'code'),
            'merchant_id' => $this->getExtraDataValue($order, ['ifood'], 'merchant_id'),
            'pickup_code' => $this->getExtraDataValue($order, ['ifood'], 'pickup_code'),
            'handover_code' => $this->getExtraDataValue($order, ['ifood'], 'handover_code'),
            'locator' => $this->getExtraDataValue($order, ['ifood'], 'locator'),
            'virtual_phone' => $this->getExtraDataValue($order, ['ifood'], 'virtual_phone'),
            'customer_phone' => $this->getExtraDataValue($order, ['ifood'], 'customer_phone'),
            'selected_payment_label' => $this->getExtraDataValue($order, ['ifood'], 'selected_payment_label'),
            'amount_paid' => $this->getExtraDataValue($order, ['ifood'], 'amount_paid'),
            'amount_pending' => $this->getExtraDataValue($order, ['ifood'], 'amount_pending'),
            'address_display' => $this->getExtraDataValue($order, ['ifood'], 'address_display'),
            'remark' => $this->getExtraDataValue($order, ['ifood'], 'remark'),
            'delivered_by' => $this->getExtraDataValue($order, ['ifood'], 'delivered_by'),
            'delivery_mode' => $this->getExtraDataValue($order, ['ifood'], 'delivery_mode'),
        ];
    }

    private function get99PrintData(Order $order): array
    {
        $contexts = ['99', '99food'];

        return [
            'code' => $this->getExtraDataValue($order, $contexts, 'code'),
            'merchant_id' => $this->getExtraDataValue($order, $contexts, 'merchant_id'),
            'pickup_code' => $this->getExtraDataValue($order, $contexts, 'pickup_code'),
            'handover_code' => $this->getExtraDataValue($order, $contexts, 'handover_code'),
            'locator' => $this->getExtraDataValue($order, $contexts, 'locator'),
            'virtual_phone' => $this->getExtraDataValue($order, $contexts, 'virtual_phone'),
            'customer_phone' => $this->getExtraDataValue($order, $contexts, 'customer_phone'),
            'selected_payment_label' => $this->getExtraDataValue($order, $contexts, 'selected_payment_label'),
            'amount_paid' => $this->getExtraDataValue($order, $contexts, 'amount_paid'),
            'amount_pending' => $this->getExtraDataValue($order, $contexts, 'amount_pending'),
            'address_display' => $this->getExtraDataValue($order, $contexts, 'address_display'),
            'remark' => $this->getExtraDataValue($order, $contexts, 'remark'),
            'delivered_by' => $this->getExtraDataValue($order, $contexts, 'delivered_by'),
            'delivery_mode' => $this->getExtraDataValue($order, $contexts, 'delivery_mode'),
        ];
    }

    private function getExtraDataValue(Order $order, array $contexts, string $fieldName): ?string
    {
        $map = $this->getOrderExtraDataMap($order);

        foreach ($contexts as $context) {
            $normalizedContext = strtolower(trim((string) $context));
            $value = $map[$normalizedContext][$fieldName] ?? null;
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        $otherInformations = $this->decodeOtherInformations($order);
        foreach ($contexts as $context) {
            foreach ($otherInformations as $key => $value) {
                if (strtolower((string) $key) !== strtolower((string) $context)) {
                    continue;
                }

                if (is_array($value) && isset($value[$fieldName]) && $value[$fieldName] !== '') {
                    return (string) $value[$fieldName];
                }
            }
        }

        return null;
    }

    private function getOrderExtraDataMap(Order $order): array
    {
        $orderId = (int) $order->getId();
        if (isset($this->extraDataCache[$orderId])) {
            return $this->extraDataCache[$orderId];
        }

        $map = [];
        foreach ($this->extraDataService->getExtraDataFromEntity($order) as $extraData) {
            if (!method_exists($extraData, 'getExtraFields') || !method_exists($extraData, 'getValue')) {
                continue;
            }

            $extraField = $extraData->getExtraFields();
            if (!$extraField) {
                continue;
            }

            $context = '';
            if (method_exists($extraField, 'getContext')) {
                $context = trim((string) $extraField->getContext());
            }

            $name = '';
            if (method_exists($extraField, 'getName')) {
                $name = trim((string) $extraField->getName());
            } elseif (method_exists($extraField, 'getFieldName')) {
                $name = trim((string) $extraField->getFieldName());
            }

            if ($context === '' || $name === '') {
                continue;
            }

            $map[strtolower($context)][$name] = (string) $extraData->getValue();
        }

        $this->extraDataCache[$orderId] = $map;
        return $map;
    }

    private function decodeOtherInformations(Order $order): array
    {
        try {
            $value = $order->getOtherInformations(true);

            if (is_array($value)) {
                return $value;
            }

            if (is_object($value)) {
                $decoded = json_decode(json_encode($value), true);
                return is_array($decoded) ? $decoded : [];
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [];
            }
        } catch (\Throwable) {
        }

        return [];
    }

    private function resolveParentGroupName(object $orderProduct): string
    {
        if (method_exists($orderProduct, 'getProductGroup')) {
            $group = $orderProduct->getProductGroup();
            if ($group && method_exists($group, 'getProductGroup')) {
                $name = trim((string) $group->getProductGroup());
                if ($name !== '') {
                    return $name;
                }
            }
        }

        $product = method_exists($orderProduct, 'getProduct') ? $orderProduct->getProduct() : null;
        if ($product && method_exists($product, 'getProductGroup')) {
            $group = $product->getProductGroup();
            if ($group && method_exists($group, 'getProductGroup')) {
                $name = trim((string) $group->getProductGroup());
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return $this->defaultGroupName;
    }

    private function resolveChildGroupName(object $orderProduct): string
    {
        if (method_exists($orderProduct, 'getProductGroup')) {
            $group = $orderProduct->getProductGroup();
            if ($group && method_exists($group, 'getProductGroup')) {
                $name = trim((string) $group->getProductGroup());
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return $this->defaultChildGroupName;
    }

    private function resolvePeopleName(object|null $people): string
    {
        if (!$people) {
            return '';
        }

        foreach (['getFantasyName', 'getName', 'getCompanyName', 'getSocialName', 'getAlias'] as $method) {
            if (method_exists($people, $method)) {
                $value = trim((string) $people->{$method}());
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function resolvePeopleDocument(object|null $people): string
    {
        if (!$people) {
            return '';
        }

        foreach (['getDocument', 'getDocumentNumber', 'getCpfCnpj', 'getCpf', 'getCnpj'] as $method) {
            if (method_exists($people, $method)) {
                $value = trim((string) $people->{$method}());
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function resolvePeoplePhone(object|null $people): string
    {
        if (!$people) {
            return '';
        }

        foreach (['getPhone', 'getCellphone', 'getMobile', 'getWhatsapp'] as $method) {
            if (!method_exists($people, $method)) {
                continue;
            }

            $value = $people->{$method}();

            if (is_scalar($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    return $text;
                }
            }

            if (is_object($value) && method_exists($value, 'getPhone')) {
                $text = trim((string) $value->getPhone());
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function printSeparator(): void
    {
        $this->printService->addLine('', '', '-');
    }

    private function printWrappedLabelValue(string $label, string $value): void
    {
        $prefix = $label . ': ';
        $continuationPrefix = str_repeat(' ', $this->stringWidth($prefix));
        $this->printWrappedBlock($prefix, $value, $continuationPrefix);
    }

    private function printWrappedBlock(string $prefix, string $text, ?string $continuationPrefix = null, int $maxWidth = 38): void
    {
        $text = $this->normalizeText($text);
        $continuationPrefix = $continuationPrefix ?? str_repeat(' ', $this->stringWidth($prefix));

        if ($text === '') {
            $this->printService->addLine(rtrim($prefix));
            return;
        }

        $firstWidth = max(8, $maxWidth - $this->stringWidth($prefix));
        $continuationWidth = max(8, $maxWidth - $this->stringWidth($continuationPrefix));

        $lines = $this->wrapText($text, $firstWidth, $continuationWidth);
        if (empty($lines)) {
            $this->printService->addLine(rtrim($prefix));
            return;
        }

        $firstLine = array_shift($lines);
        $this->printService->addLine($prefix . $firstLine);

        foreach ($lines as $line) {
            $this->printService->addLine($continuationPrefix . $line);
        }
    }

    private function wrapText(string $text, int $firstWidth, ?int $nextWidth = null): array
    {
        $nextWidth ??= $firstWidth;
        $text = $this->normalizeText($text);

        if ($text === '') {
            return [];
        }

        $words = explode(' ', $text);
        $lines = [];
        $current = '';
        $currentLimit = $firstWidth;

        foreach ($words as $word) {
            if ($this->stringWidth($word) > $currentLimit && $current === '') {
                $parts = $this->splitLongWord($word, $currentLimit);
                foreach ($parts as $index => $part) {
                    $isLastPart = $index === array_key_last($parts);
                    if ($isLastPart) {
                        $current = $part;
                    } else {
                        $lines[] = $part;
                        $currentLimit = $nextWidth;
                    }
                }
                continue;
            }

            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->stringWidth($candidate) <= $currentLimit) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            $currentLimit = $nextWidth;

            if ($this->stringWidth($word) > $currentLimit) {
                $parts = $this->splitLongWord($word, $currentLimit);
                foreach ($parts as $index => $part) {
                    $isLastPart = $index === array_key_last($parts);
                    if ($isLastPart) {
                        $current = $part;
                    } else {
                        $lines[] = $part;
                    }
                }
            } else {
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function splitLongWord(string $word, int $limit): array
    {
        $limit = max(1, $limit);
        $parts = [];
        $length = $this->stringWidth($word);

        for ($offset = 0; $offset < $length; $offset += $limit) {
            $parts[] = $this->substring($word, $offset, $limit);
        }

        return $parts;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    private function upper(string $text): string
    {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($text, 'UTF-8')
            : strtoupper($text);
    }

    private function stringWidth(string $text): int
    {
        if (function_exists('mb_strwidth')) {
            return mb_strwidth($text, 'UTF-8');
        }

        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }

        return strlen($text);
    }

    private function substring(string $text, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, $start, $length, 'UTF-8');
        }

        return substr($text, $start, $length);
    }
}
