<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\People;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class OrderCommercialContextService
{
    public const CHARGE_CONFIG_KEY = 'order-charge-enabled';
    public const PAY_BEFORE_PRODUCTION_CONFIG_KEY = 'pay-before-production';
    public const CHARGE_MODE_LOCAL = 'local';
    public const CHARGE_MODE_REMOTE = 'remote';
    public const CHARGE_MODE_EXTERNAL = 'external';
    public const CHARGE_MODES = [
        self::CHARGE_MODE_LOCAL,
        self::CHARGE_MODE_REMOTE,
        self::CHARGE_MODE_EXTERNAL,
    ];

    private ?Request $request;

    public function __construct(
        private EntityManagerInterface $manager,
        private PeopleService $peopleService,
        private DeviceService $deviceService,
        RequestStack $requestStack,
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    public function prepare(Order $order, bool $freeze = false): void
    {
        if ($order->getChannel() === null) {
            $order->setChannel($this->inferChannelFromApp($order->getApp()));
        }

        $temporalPolicySource = $this->applyTrustedTemporalPolicy($order);
        $this->assertCanonicalValues($order);
        $mainOrder = $this->resolveMainOrder($order);
        $this->assertMainOrderIsTenantSafe($order, $mainOrder);
        $this->assertTemporalPolicyIsCompatible($order, $mainOrder);

        $snapshot = $order->getOperationalSnapshot();
        if ($freeze && is_array($snapshot) && isset($snapshot['confirmedAt'])) {
            $this->assertFrozenContextUnchanged($order, $mainOrder, $snapshot);
            return;
        }

        $now = (new \DateTimeImmutable())->format(DATE_ATOM);
        $nextSnapshot = [
            'version' => 1,
            'channel' => $order->getChannel(),
            'app' => $order->getApp(),
            'fulfillmentType' => $order->getFulfillmentType(),
            'payBeforeProduction' => $order->isPayBeforeProductionRequired(),
            'payBeforeProductionSource' => $temporalPolicySource,
            'linkType' => $this->resolveLinkType($order, $mainOrder),
            'appliedAt' => $now,
        ];

        if ($freeze) {
            $nextSnapshot['confirmedAt'] = $now;
        }

        $order->setOperationalSnapshot($nextSnapshot);
    }

    public function assertConfirmationAllowed(Order $order): void
    {
        $this->prepare($order);

        if (!$order->isPayBeforeProductionRequired()) {
            return;
        }

        if (!$this->isOwnOrderFullyPaid($order)) {
            throw new BadRequestHttpException(
                'Pagamento integral da venda e obrigatorio antes da confirmacao e producao.'
            );
        }
    }

    public function freezeConfirmedContext(Order $order): void
    {
        $this->prepare($order, true);
    }

    public function isOwnOrderFullyPaid(Order $order): bool
    {
        $paidAmount = 0.0;

        foreach ($order->getInvoice() as $orderInvoice) {
            if (!$orderInvoice instanceof OrderInvoice) {
                continue;
            }

            $invoice = $orderInvoice->getInvoice();
            $realStatus = strtolower(trim((string) $invoice?->getStatus()?->getRealStatus()));
            if ($realStatus !== 'closed') {
                continue;
            }

            $paidAmount += (float) $orderInvoice->getRealPrice();
        }

        return $paidAmount + 0.00001 >= (float) $order->getPrice();
    }

    public function assertChargeAllowed(Order $order, string $mode = self::CHARGE_MODE_LOCAL): void
    {
        $normalizedMode = strtolower(trim($mode));
        if (!in_array($normalizedMode, self::CHARGE_MODES, true)) {
            throw new BadRequestHttpException('Modalidade de cobranca invalida.');
        }

        $capability = $this->resolveChargeCapability($order);
        if (($capability[$normalizedMode] ?? false) === true) {
            return;
        }

        throw new AccessDeniedHttpException(
            $normalizedMode === self::CHARGE_MODE_LOCAL
                ? 'Este contexto nao possui capacidade para cobranca local.'
                : 'Este contexto nao possui capacidade para a modalidade de cobranca solicitada.'
        );
    }

    /**
     * @return array{enabled: bool, local: bool, remote: bool, external: bool, source: string, appType: string}
     */
    public function resolveChargeCapability(Order $order): array
    {
        $provider = $order->getProvider();
        if (!$provider instanceof People || !$this->actorCanAccessCompany($provider)) {
            return $this->deniedCapability('company-denied');
        }

        $device = $this->resolveCurrentDevice();
        if (!$device instanceof Device) {
            return $this->deniedCapability('device-missing');
        }

        $configuredCapability = $this->resolveConfiguredChargeCapability(
            $device,
            $provider,
        );

        return [
            'enabled' => $configuredCapability['enabled'],
            'local' => $configuredCapability['local'],
            'remote' => $configuredCapability['enabled'],
            'external' => $configuredCapability['enabled'],
            'source' => $configuredCapability['source'],
            'appType' => $configuredCapability['appType'],
        ];
    }

    private function assertCanonicalValues(Order $order): void
    {
        if (!in_array($order->getChannel(), Order::CHANNELS, true)) {
            throw new BadRequestHttpException('Canal do pedido invalido.');
        }

        $fulfillmentType = $order->getFulfillmentType();
        if ($fulfillmentType !== null && !in_array($fulfillmentType, Order::FULFILLMENT_TYPES, true)) {
            throw new BadRequestHttpException('Tipo de fulfillment invalido.');
        }
    }

    private function applyTrustedTemporalPolicy(Order $order): string
    {
        $snapshot = $order->getOperationalSnapshot();
        if (is_array($snapshot) && array_key_exists('payBeforeProduction', $snapshot)) {
            $snapshotSource = (string) ($snapshot['payBeforeProductionSource'] ?? 'order-snapshot');
            $isConfirmed = isset($snapshot['confirmedAt']);
            $canResolveProvisionalDefault = !$isConfirmed
                && $snapshotSource === 'default'
                && $order->getDevice() instanceof Device;

            if (!$canResolveProvisionalDefault) {
                $order->setPayBeforeProduction($snapshot['payBeforeProduction'] === true);

                return $snapshotSource;
            }
        }

        $provider = $order->getProvider();
        $device = $order->getDevice();
        if ($provider instanceof People && $device instanceof Device) {
            $configuredValues = [];
            foreach ($this->deviceService->findDeviceConfigs($device, $provider) as $deviceConfig) {
                if (!$deviceConfig instanceof DeviceConfig) {
                    continue;
                }

                $configs = $deviceConfig->getConfigs(true);
                if (
                    !is_array($configs)
                    || !array_key_exists(self::PAY_BEFORE_PRODUCTION_CONFIG_KEY, $configs)
                ) {
                    continue;
                }

                $configuredValues[] = $this->normalizeBoolean(
                    $configs[self::PAY_BEFORE_PRODUCTION_CONFIG_KEY],
                );
            }

            if ($configuredValues !== []) {
                // Conflicting rows must never weaken a restrictive policy.
                $order->setPayBeforeProduction(in_array(true, $configuredValues, true));

                return 'device-config';
            }
        }

        return $order->getPayBeforeProduction() !== null
            ? 'server-assigned'
            : 'default';
    }

    private function assertTemporalPolicyIsCompatible(Order $order, ?Order $mainOrder): void
    {
        if (!$order->isPayBeforeProductionRequired()) {
            return;
        }

        $rootType = strtolower(trim((string) ($mainOrder?->getOrderType() ?? $order->getOrderType())));
        if (in_array($rootType, [Order::ORDER_TYPE_TABLE, Order::ORDER_TYPE_TAB], true)) {
            throw new BadRequestHttpException(
                'pay_before_production nao e suportado em mesa ou comanda sem alocacao financeira deterministica por rodada.'
            );
        }
    }

    private function resolveMainOrder(Order $order): ?Order
    {
        if ($order->getMainOrder() instanceof Order) {
            return $order->getMainOrder();
        }

        $mainOrderId = (int) ($order->getMainOrderId() ?? 0);
        if ($mainOrderId <= 0) {
            return null;
        }

        $mainOrder = $this->manager->getRepository(Order::class)->find($mainOrderId);
        if (!$mainOrder instanceof Order) {
            throw new BadRequestHttpException('Pedido raiz informado nao foi encontrado.');
        }

        return $mainOrder;
    }

    private function assertMainOrderIsTenantSafe(Order $order, ?Order $mainOrder): void
    {
        if (!$mainOrder instanceof Order) {
            return;
        }

        if ($mainOrder === $order || ($order->getId() && $order->getId() === $mainOrder->getId())) {
            throw new BadRequestHttpException('Pedido nao pode ser vinculado a si mesmo.');
        }

        $orderType = strtolower(trim((string) $order->getOrderType()));
        $mainOrderType = strtolower(trim((string) $mainOrder->getOrderType()));
        $requiresSameCommercialTenant = in_array(
            $orderType,
            [Order::ORDER_TYPE_CART, Order::ORDER_TYPE_SALE],
            true,
        ) || in_array(
            $mainOrderType,
            [Order::ORDER_TYPE_TABLE, Order::ORDER_TYPE_TAB],
            true,
        );
        if (!$requiresSameCommercialTenant) {
            return;
        }

        $providerId = (int) ($order->getProvider()?->getId() ?? 0);
        $mainProviderId = (int) ($mainOrder->getProvider()?->getId() ?? 0);
        if ($providerId <= 0 || $mainProviderId <= 0 || $providerId !== $mainProviderId) {
            throw new BadRequestHttpException('Vinculo de pedido entre tenants nao e permitido.');
        }
    }

    private function assertFrozenContextUnchanged(Order $order, ?Order $mainOrder, array $snapshot): void
    {
        $current = [
            'channel' => $order->getChannel(),
            'app' => $order->getApp(),
            'fulfillmentType' => $order->getFulfillmentType(),
            'payBeforeProduction' => $order->isPayBeforeProductionRequired(),
            'linkType' => $this->resolveLinkType($order, $mainOrder),
        ];

        foreach ($current as $key => $value) {
            if (($snapshot[$key] ?? null) !== $value) {
                throw new BadRequestHttpException(
                    'Contexto comercial confirmado nao pode ser alterado livremente.'
                );
            }
        }
    }

    private function resolveLinkType(Order $order, ?Order $mainOrder): string
    {
        $type = strtolower(trim((string) ($mainOrder?->getOrderType() ?? $order->getOrderType())));

        return in_array($type, [Order::ORDER_TYPE_TABLE, Order::ORDER_TYPE_TAB], true)
            ? $type
            : 'none';
    }

    private function inferChannelFromApp(?string $app): string
    {
        return match (strtolower(trim((string) $app))) {
            'pos' => Order::CHANNEL_POS,
            'shop' => Order::CHANNEL_SHOP,
            default => Order::CHANNEL_EXTERNAL,
        };
    }

    private function actorCanAccessCompany(People $provider): bool
    {
        $providerId = (int) $provider->getId();

        foreach ($this->peopleService->getMyCompanies() as $company) {
            $companyId = $company instanceof People ? (int) $company->getId() : (int) $company;
            if ($providerId > 0 && $companyId === $providerId) {
                return true;
            }
        }

        return false;
    }

    private function resolveCurrentDevice(): ?Device
    {
        $deviceIdentifier = trim((string) $this->request?->headers->get('DEVICE', ''));
        if ($deviceIdentifier === '') {
            return null;
        }

        $device = $this->manager->getRepository(Device::class)->findOneBy([
            'device' => $deviceIdentifier,
        ]);

        return $device instanceof Device ? $device : null;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Device metadata and request headers are client-controlled and therefore
     * cannot identify the application or grant a financial capability. Only
     * persisted DeviceConfig rows protected by the tenant-administrative guard
     * identify a known POS/Manager context. Known legacy contexts are
     * explicitly backfilled; a new missing or ambiguous context fails closed.
     *
     * @return array{enabled: bool, local: bool, source: string, appType: string}
     */
    private function resolveConfiguredChargeCapability(Device $device, People $provider): array
    {
        $deviceConfigs = $this->deviceService->findDeviceConfigs($device, $provider);

        $explicitValues = [];
        $explicitPdvValues = [];
        $hasManagerContext = false;
        $hasPdvContext = false;
        $seenIds = [];
        foreach ($deviceConfigs as $deviceConfig) {
            if (!$deviceConfig instanceof DeviceConfig) {
                continue;
            }

            $configId = (int) $deviceConfig->getId();
            if ($configId > 0 && isset($seenIds[$configId])) {
                continue;
            }
            if ($configId > 0) {
                $seenIds[$configId] = true;
            }

            $configType = strtoupper(trim($deviceConfig->getType()));
            $hasManagerContext = $hasManagerContext || $configType === 'MANAGER';
            $hasPdvContext = $hasPdvContext || $configType === 'PDV';

            $configs = $deviceConfig->getConfigs(true);
            if (!is_array($configs) || !array_key_exists(self::CHARGE_CONFIG_KEY, $configs)) {
                continue;
            }

            $explicitValue = $this->normalizeBoolean($configs[self::CHARGE_CONFIG_KEY]);
            $explicitValues[] = $explicitValue;
            if ($configType === 'PDV') {
                $explicitPdvValues[] = $explicitValue;
            }
        }

        $hasTrustedContext = $hasManagerContext || $hasPdvContext;
        $hasExplicitCapability = $explicitValues !== [];
        $enabled = $hasTrustedContext
            && $hasExplicitCapability
            && !in_array(false, $explicitValues, true);
        $local = $enabled
            && $hasPdvContext
            && !$hasManagerContext
            && $explicitPdvValues !== []
            && !in_array(false, $explicitPdvValues, true);

        return [
            'enabled' => $enabled,
            'local' => $local,
            'source' => !$hasTrustedContext
                ? 'device-context-untrusted'
                : ($hasExplicitCapability ? 'device-config' : 'device-config-missing'),
            'appType' => $hasManagerContext
                ? 'MANAGER'
                : ($hasPdvContext ? 'POS' : ''),
        ];
    }

    private function deniedCapability(string $source): array
    {
        return [
            'enabled' => false,
            'local' => false,
            'remote' => false,
            'external' => false,
            'source' => $source,
            'appType' => '',
        ];
    }
}
