<?php

namespace ControleOnline\Service;

use DateTimeImmutable;
use ControleOnline\Entity\Category;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;

class OrderActionService
{
    use OrderActionOperations1;

    private const ORDER_ACTION_KEY = 'order_action';
    public const ORDER_CANCELLATION_REASON_CONTEXT = 'order_cancellation_reason';

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

    private function normalizeOptionalNumericId(mixed $value): ?int
    {
        $normalized = (int) preg_replace('/\D+/', '', $this->normalizeString($value));

        return $normalized > 0 ? $normalized : null;
    }

    /**
     * Reattach People that may come detached from the security token / another
     * EntityManager so Doctrine flush does not throw
     * "A new entity was found through the relationship ... cascade persist".
     */
    private function reattachPeople(?People $people): ?People
    {
        if (!$people instanceof People) {
            return null;
        }

        $id = $people->getId();
        if (!$id) {
            // Transient entity (unit tests / not yet persisted) — keep as provided.
            return $people;
        }

        if ($this->entityManager->contains($people)) {
            return $people;
        }

        $managed = $this->entityManager->getRepository(People::class)->find($id);

        return $managed instanceof People ? $managed : $people;
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
        $raw = $order->getOtherInformations(true);
        if (is_object($raw)) {
            $otherInformations = $raw;
        } elseif (is_array($raw)) {
            $otherInformations = (object) $raw;
        } else {
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

    public function discardDraft(
        Order $order,
        int $expectedMainOrderId,
        ?string $reason = null,
        ?People $canceledBy = null,
        ?People $company = null,
    ): array {
        return $this->entityManager->wrapInTransaction(function () use ($order, $expectedMainOrderId, $reason, $canceledBy, $company): array {
            // Re-read under a row lock: a concurrent confirmation must not cancel a sale.
            $this->entityManager->refresh($order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if ($order->getOrderType() !== 'cart' || $this->isTerminalOrder($order)
                || $expectedMainOrderId <= 0 || (int) $order->getMainOrderId() !== $expectedMainOrderId
                || ($company instanceof People && $order->getProvider()?->getId() !== $company->getId())) {
                throw new \InvalidArgumentException('O rascunho foi alterado ou enviado. Atualize a consulta.');
            }
            return $this->cancel($order, null, $reason, $canceledBy, $company);
        });
    }

    public function cancel(
        Order $order,
        mixed $reasonId = null,
        ?string $reason = null,
        ?People $canceledBy = null,
        ?People $company = null,
    ): array
    {
        if ($this->isTerminalOrder($order)) {
            return $this->buildTerminalOrderResponse();
        }

        // Security token People may be detached; reattach before association + flush.
        $canceledBy = $this->reattachPeople($canceledBy);
        $company = $this->reattachPeople($company);

        $cancellationReason = $this->resolveCancellationReason($order, $reasonId, $company);
        $canceledById = $canceledBy instanceof People ? $canceledBy->getId() : null;

        if ($this->isDeliveryOrder($order)) {
            $this->persistOrderAction($order, 'cancel', [
                'reason_id' => $reasonId,
                'reason' => $reason,
                'canceled_by_id' => $canceledById,
            ]);
            $this->applyCancellationAudit($order, $cancellationReason, $canceledBy);

            return $this->applyDeliveryStatus($order, 'canceled', 'canceled');
        }

        if ($this->isIfoodOrder($order) && $this->iFoodService instanceof iFoodService) {
            $result = $this->iFoodService->performCancelAction(
                $order,
                $reason,
                $reasonId !== null ? $this->normalizeString($reasonId) : null
            );

            if (($result['errno'] ?? 1) === 0) {
                $this->applyCancellationAudit($order, $cancellationReason, $canceledBy);
                $this->entityManager->persist($order);
                $this->entityManager->flush();
            }

            return $result;
        }

        $this->persistOrderAction($order, 'cancel', [
            'reason_id' => $reasonId,
            'reason' => $reason,
            'canceled_by_id' => $canceledById,
        ]);
        $this->applyCancellationAudit($order, $cancellationReason, $canceledBy);

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

        // Loyalty parent orders must keep their own type when they are closed as a reward card.
        if ($this->normalizeStatusValue($order->getOrderType()) !== Order::ORDER_TYPE_FIDELITY) {
            // Delivered is a sale-only terminal transition, so a draft cart must be promoted first.
            $this->orderService->convertDraftOrderToSale($order);
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

        return $this->applyLocalStatus($order, 'closed', 'closed');
    }

    private function applyStatus(Order $order, string $realStatus, string $statusName, string $context): array
    {
        $candidates = array_values(array_unique(array_filter([
            $statusName,
            $realStatus,
            // Common PT-BR labels used in legacy status rows.
            $realStatus === 'canceled' ? 'cancelado' : null,
            $realStatus === 'canceled' ? 'Cancelado' : null,
            $realStatus === 'closed' ? 'fechado' : null,
            $realStatus === 'closed' ? 'Fechado' : null,
            $realStatus === 'open' ? 'aberto' : null,
            $realStatus === 'pending' ? 'pendente' : null,
        ], static fn ($value) => is_string($value) && trim($value) !== '')));

        $novoStatus = null;
        $lastError = null;
        foreach ($candidates as $candidateName) {
            try {
                $novoStatus = $this->statusService->discoveryStatus($realStatus, $candidateName, $context);
                if ($novoStatus) {
                    break;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        if (!$novoStatus) {
            $detail = $lastError instanceof \Throwable ? $lastError->getMessage() : '';
            return [
                'errno' => 1,
                'errmsg' => 'Status não encontrado: ' . $realStatus . ($detail !== '' ? ' (' . $detail . ')' : ''),
            ];
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
