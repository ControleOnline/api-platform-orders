<?php

namespace ControleOnline\Service;

use DateTimeImmutable;
use ControleOnline\Entity\Order;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;

class OrderActionService
{
    private const ORDER_ACTION_KEY = 'order_action';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private StatusService $statusService,
        private ?iFoodService $iFoodService = null,
        private ?Food99Service $food99Service = null,
    ) {}

    private function normalizeString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function sanitizeActionPayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = $this->normalizeString($key);
            if ($normalizedKey === '') {
                continue;
            }

            if (is_bool($value)) {
                $sanitized[$normalizedKey] = $value;
                continue;
            }

            $normalizedValue = $this->normalizeString($value);
            if ($normalizedValue === '') {
                continue;
            }

            $sanitized[$normalizedKey] = $normalizedValue;
        }

        return $sanitized;
    }

    private function persistOrderAction(Order $order, string $action, array $payload = [], bool $remoteSync = true): void
    {
        $otherInformations = $order->getOtherInformations(true);
        if (!is_object($otherInformations)) {
            $otherInformations = (object) [];
        }

        $otherInformations->{self::ORDER_ACTION_KEY} = [
            'name' => $action,
            'remote_sync' => $remoteSync,
            'requested_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'payload' => $this->sanitizeActionPayload($payload),
        ];

        $order->setOtherInformations($otherInformations);
    }

    private function isTerminalOrder(Order $order): bool
    {
        $realStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));

        return in_array($realStatus, ['canceled', 'cancelled', 'closed'], true);
    }

    private function normalizeStatusValue(mixed $value): string
    {
        return strtolower(trim((string) ($value ?? '')));
    }

    private function isPosOrShopOrder(Order $order): bool
    {
        $app = $this->normalizeStatusValue($order->getApp());

        return in_array($app, ['pos', 'shop'], true);
    }

    private function isIfoodOrder(Order $order): bool
    {
        return $this->normalizeStatusValue($order->getApp()) === strtolower(Order::APP_IFOOD);
    }

    private function isFood99Order(Order $order): bool
    {
        return $this->normalizeStatusValue($order->getApp()) === strtolower(Order::APP_FOOD99);
    }

    private function buildTerminalOrderResponse(): array
    {
        return [
            'errno' => 10001,
            'errmsg' => 'Pedido em estado final nao pode mais ser alterado.',
        ];
    }

    public function getCapabilities(Order $order): array
    {
        $realStatus = $this->normalizeStatusValue($order->getStatus()?->getRealStatus());
        $status = $this->normalizeStatusValue($order->getStatus()?->getStatus());
        $terminal = in_array($realStatus, ['canceled', 'cancelled', 'closed'], true);

        $isInitialPosOrShopState = $this->isPosOrShopOrder($order)
            && $realStatus === 'open'
            && in_array($status, ['', 'open', 'paid', 'confirmed'], true);
        $isPreparingPosOrShopState = $this->isPosOrShopOrder($order)
            && $realStatus === 'open'
            && $status === 'preparing';
        $isReadyPosOrShopState = $this->isPosOrShopOrder($order)
            && $realStatus === 'pending'
            && $status === 'ready';
        $isDeliveringPosOrShopState = $this->isPosOrShopOrder($order)
            && $realStatus === 'pending'
            && $status === 'way';

        $canConfirm = !$terminal && (!$this->isPosOrShopOrder($order) || $isInitialPosOrShopState);
        $canReady = !$terminal && (!$this->isPosOrShopOrder($order) || $isPreparingPosOrShopState);
        $canDelivered = !$terminal && (!$this->isPosOrShopOrder($order) || $isReadyPosOrShopState || $isDeliveringPosOrShopState);

        return [
            'realStatus' => $realStatus,
            'can_cancel' => !$terminal,
            'can_confirm' => $canConfirm,
            'can_ready' => $canReady,
            'can_delivered' => $canDelivered,
            'is_delivering' => $isDeliveringPosOrShopState,
            'is_terminal' => $terminal,
        ];
    }

    public function getCancelReasons(Order $order): array
    {
        if ($this->isIfoodOrder($order) && $this->iFoodService instanceof iFoodService) {
            return [
                'errno' => 0,
                'errmsg' => 'ok',
                'data' => [
                    'reasons' => $this->iFoodService->getIfoodCancellationReasons($order),
                ],
            ];
        }

        if ($this->isFood99Order($order) && $this->food99Service instanceof Food99Service) {
            return $this->food99Service->getOrderCancelReasons($order);
        }

        return ['errno' => 0, 'errmsg' => 'ok', 'data' => ['reasons' => []]];
    }

    public function confirm(Order $order): array
    {
        if ($this->isTerminalOrder($order)) {
            return $this->buildTerminalOrderResponse();
        }

        $this->persistOrderAction($order, 'confirm');

        return $this->aplicarStatusLocal($order, 'open', 'preparing');
    }

    public function cancel(Order $order, mixed $reasonId = null, ?string $reason = null): array
    {
        if ($this->isTerminalOrder($order)) {
            return $this->buildTerminalOrderResponse();
        }

        $this->persistOrderAction($order, 'cancel', [
            'reason_id' => $reasonId,
            'reason' => $reason,
        ]);

        return $this->aplicarStatusLocal($order, 'canceled', 'canceled');
    }

    public function ready(Order $order): array
    {
        if ($this->isTerminalOrder($order)) {
            return $this->buildTerminalOrderResponse();
        }

        $this->persistOrderAction($order, 'ready');

        return $this->aplicarStatusLocal($order, 'pending', 'ready');
    }

    public function delivered(
        Order $order,
        ?string $deliveryCode = null,
        ?string $locator = null,
        bool $deferStatusUpdate = false
    ): array
    {
        if ($this->isTerminalOrder($order)) {
            return $this->buildTerminalOrderResponse();
        }

        $shouldRemoteSync = $deferStatusUpdate
            || $this->normalizeString($deliveryCode) !== ''
            || $this->normalizeString($locator) !== '';

        $this->persistOrderAction($order, 'delivered', [
            'delivery_code' => $deliveryCode,
            'locator' => $locator,
        ], $shouldRemoteSync);

        if ($shouldRemoteSync) {
            $this->entityManager->persist($order);
            $this->entityManager->flush();

            return ['errno' => 0, 'errmsg' => 'ok'];
        }

        return $this->aplicarStatusLocal($order, 'closed', 'closed');
    }

    private function aplicarStatusLocal(Order $order, string $status, string $realStatus): array
    {
        $novoStatus = $this->statusService->discoveryStatus($status, $realStatus, 'order');

        if (!$novoStatus) {
            return ['errno' => 1, 'errmsg' => 'Status não encontrado: ' . $realStatus];
        }

        $order->setStatus($novoStatus);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return ['errno' => 0, 'errmsg' => 'ok'];
    }
}
