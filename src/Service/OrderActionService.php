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
        private OrderService $orderService,
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

    private function isDeliveryOrder(Order $order): bool
    {
        return $this->normalizeStatusValue($order->getOrderType()) === Order::ORDER_TYPE_DELIVERY;
    }

    private function isShopOrder(Order $order): bool
    {
        return $this->normalizeStatusValue($order->getApp()) === 'shop';
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

        if ($this->isDeliveryOrder($order)) {
            $awaitingAcceptance = in_array($status, [
                'aguardando aceite',
                'awaiting acceptance',
                'waiting acceptance',
                'pending acceptance',
                'acceptance pending',
                'pending',
                'pendente',
            ], true);
            $accepted = in_array($status, ['aceito', 'accepted', 'accept'], true) || $realStatus === 'accepted';
            $inRoute = in_array($status, [
                'way',
                'away',
                'en route',
                'in route',
                'on route',
                'picked up',
                'pickup',
                'dispatch',
                'delivery',
                'delivering',
            ], true);
            $deliveryTerminal = $terminal || in_array($status, [
                'delivered',
                'entregue',
                'finished',
                'finalizado',
                'canceled',
                'cancelado',
                'cancelled',
                'cancel',
            ], true);

            return [
                'realStatus' => $realStatus,
                'can_cancel' => $awaitingAcceptance && !$deliveryTerminal,
                'can_confirm' => $awaitingAcceptance && !$deliveryTerminal,
                'can_ready' => false,
                'can_delivered' => ($accepted || $inRoute) && !$deliveryTerminal,
                'is_delivering' => $accepted || $inRoute,
                'is_terminal' => $deliveryTerminal,
            ];
        }

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

        if ($this->isDeliveryOrder($order)) {
            $this->persistOrderAction($order, 'confirm');

            return $this->applyDeliveryStatus($order, 'accepted', 'aceito');
        }

        if ($this->isShopOrder($order) && $order->getAddressDestination() === null) {
            return [
                'errno' => 10002,
                'errmsg' => 'Pedido do Shop sem endereco de entrega valido.',
            ];
        }

        $this->persistOrderAction($order, 'confirm');
        $wasPromotedToSale = false;
        if ($this->isPosOrShopOrder($order)) {
            $wasPromotedToSale = $this->orderService->convertDraftOrderToSale($order);
        }

        $result = $this->applyLocalStatus($order, 'open', 'preparing');
        if ($wasPromotedToSale && ($result['errno'] ?? 1) === 0) {
            // The real order only exists after the cart is promoted to sale.
            $this->orderService->dispatchOrderCreated($order);
        }

        return $result;
    }

    public function cancel(Order $order, mixed $reasonId = null, ?string $reason = null): array
    {
        if ($this->isTerminalOrder($order)) {
            return $this->buildTerminalOrderResponse();
        }

        if ($this->isDeliveryOrder($order)) {
            $this->persistOrderAction($order, 'cancel', [
                'reason_id' => $reasonId,
                'reason' => $reason,
            ]);

            return $this->applyDeliveryStatus($order, 'canceled', 'canceled');
        }

        if ($this->isIfoodOrder($order) && $this->iFoodService instanceof iFoodService) {
            return $this->iFoodService->performCancelAction(
                $order,
                $reason,
                $reasonId !== null ? $this->normalizeString($reasonId) : null
            );
        }

        $this->persistOrderAction($order, 'cancel', [
            'reason_id' => $reasonId,
            'reason' => $reason,
        ]);

        return $this->applyLocalStatus($order, 'canceled', 'canceled');
    }

    public function ready(Order $order): array
    {
        if ($this->isTerminalOrder($order)) {
            return $this->buildTerminalOrderResponse();
        }

        if ($this->isIfoodOrder($order) && $this->iFoodService instanceof iFoodService) {
            return $this->iFoodService->performReadyAction($order);
        }

        $this->persistOrderAction($order, 'ready');

        return $this->applyLocalStatus($order, 'pending', 'ready');
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

        if ($this->isDeliveryOrder($order)) {
            $this->persistOrderAction($order, 'delivered', [
                'delivery_code' => $deliveryCode,
                'locator' => $locator,
            ], false);

            return $this->applyDeliveryStatus($order, 'closed', 'closed');
        }

        if ($this->isIfoodOrder($order) && $this->iFoodService instanceof iFoodService) {
            return $this->iFoodService->performDeliveredAction($order, $deliveryCode, $locator);
        }

        // Delivered is a sale-only terminal transition, so a draft cart must be promoted first.
        $this->orderService->convertDraftOrderToSale($order);

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

        return $this->applyLocalStatus($order, 'closed', 'closed');
    }

    private function applyStatus(Order $order, string $realStatus, string $statusName, string $context): array
    {
        $novoStatus = $this->statusService->discoveryStatus($realStatus, $statusName, $context);

        if (!$novoStatus) {
            return ['errno' => 1, 'errmsg' => 'Status não encontrado: ' . $realStatus];
        }

        $order->setStatus($novoStatus);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return ['errno' => 0, 'errmsg' => 'ok'];
    }

    private function applyLocalStatus(Order $order, string $realStatus, string $statusName): array
    {
        return $this->applyStatus($order, $realStatus, $statusName, 'order');
    }

    private function applyDeliveryStatus(Order $order, string $realStatus, string $statusName): array
    {
        return $this->applyStatus($order, $realStatus, $statusName, 'delivery');
    }
}
