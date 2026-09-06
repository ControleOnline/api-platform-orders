<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\People;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Canonical order finalization (checkout) contract for all operational modes.
 * Checkout is the finalization process; Invoice is only a financial consequence.
 *
 * Charge capacity and pay_before_production enforcement are owned by T1
 * (api-platform-orders#12 / OrderCommercialContextService). When that service
 * is present on the runtime line, wire it via a follow-up or RC that includes both.
 *
 * @see https://github.com/ControleOnline/api-community/issues/60
 */
class OrderCheckoutService
{
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_REFUSED = 'refused';
    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_CONFLICT = 'conflict';
    public const OUTCOME_RECOVERABLE_FAILURE = 'recoverable_failure';
    public const OUTCOME_CANCELED = 'canceled';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderService $orderService,
        private OrderActionService $orderActionService,
        private OrderCheckoutIdempotencyStore $idempotencyStore,
        private ?OrderInvoiceService $orderInvoiceService = null,
    ) {}

    /**
     * @param array{idempotencyKey?: string, payment?: array, confirm?: bool, mode?: string} $payload
     */
    public function checkout(Order $order, array $payload = [], ?People $actor = null): array
    {
        $idempotencyKey = $this->idempotencyStore->normalizeKey($payload['idempotencyKey'] ?? null);
        $confirm = array_key_exists('confirm', $payload) ? (bool) $payload['confirm'] : true;
        $paymentPayload = is_array($payload['payment'] ?? null) ? $payload['payment'] : null;
        $mode = $this->normalizeString($payload['mode'] ?? null);

        if ($idempotencyKey !== null) {
            try {
                $cached = $this->idempotencyStore->find($order, $idempotencyKey, $payload);
                if ($cached !== null) {
                    return $cached;
                }
            } catch (ConflictHttpException $e) {
                return $this->envelope(self::OUTCOME_CONFLICT, $order, null, 409, $e->getMessage());
            }
        }

        if ($this->isTerminalCanceled($order)) {
            return $this->envelope(
                self::OUTCOME_CANCELED,
                $order,
                null,
                1,
                'Order is canceled and cannot be finalized.'
            );
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $promoted = false;
        $orderInvoice = null;

        try {
            $orderType = $this->normalizeString($order->getOrderType());
            if ($orderType === Order::ORDER_TYPE_CART || $orderType === '') {
                $promoted = $this->orderService->convertDraftOrderToSale($order);
            }

            if ($paymentPayload !== null) {
                $orderInvoice = $this->applyPayment($order, $paymentPayload);
            }

            if ($confirm) {
                $confirmResult = $this->orderActionService->confirm($order);
                if (($confirmResult['errno'] ?? 1) !== 0) {
                    $connection->rollBack();
                    return $this->envelope(
                        self::OUTCOME_REFUSED,
                        $order,
                        $orderInvoice,
                        (int) ($confirmResult['errno'] ?? 1),
                        (string) ($confirmResult['errmsg'] ?? 'Confirmation refused.'),
                        $promoted,
                        $mode
                    );
                }
            }

            if ($promoted) {
                $this->orderService->dispatchOrderCreated($order);
            }

            $this->entityManager->persist($order);
            $this->entityManager->flush();
            $connection->commit();
        } catch (ConflictHttpException $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            return $this->envelope(self::OUTCOME_CONFLICT, $order, null, 409, $e->getMessage());
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            return $this->envelope(self::OUTCOME_RECOVERABLE_FAILURE, $order, null, 500, $e->getMessage());
        }

        $envelope = $this->envelope(self::OUTCOME_SUCCESS, $order, $orderInvoice, 0, 'ok', $promoted, $mode);

        if ($idempotencyKey !== null) {
            $this->idempotencyStore->store($order, $idempotencyKey, $payload, $envelope);
            $this->entityManager->persist($order);
            $this->entityManager->flush();
        }

        return $envelope;
    }

    private function applyPayment(Order $order, array $paymentPayload): OrderInvoice
    {
        if ($this->orderInvoiceService === null) {
            throw new BadRequestHttpException('Payment cannot be applied: invoice service unavailable.');
        }

        if (!isset($paymentPayload['order'])) {
            $paymentPayload['order'] = $order->getId();
        }

        return $this->orderInvoiceService->createFromPayload($paymentPayload);
    }

    private function isTerminalCanceled(Order $order): bool
    {
        $real = $this->normalizeString($order->getStatus()?->getRealStatus());

        return in_array($real, ['canceled', 'cancelled'], true);
    }

    private function normalizeString(mixed $value): string
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function envelope(
        string $outcome,
        Order $order,
        ?OrderInvoice $orderInvoice,
        int $errno,
        string $errmsg,
        bool $promoted = false,
        string $mode = '',
    ): array {
        $status = $order->getStatus();
        $balance = $this->resolveBalance($order);
        $orderType = $this->normalizeString($order->getOrderType());
        $realStatus = $this->normalizeString($status?->getRealStatus());

        return [
            'outcome' => $outcome,
            'errno' => $errno,
            'errmsg' => $errmsg,
            'promotedToSale' => $promoted,
            'mode' => $mode !== '' ? $mode : null,
            'order' => [
                'id' => $order->getId(),
                'orderType' => $order->getOrderType(),
                'status' => $status?->getStatus(),
                'realStatus' => $status?->getRealStatus(),
                'price' => $order->getPrice(),
                'balance' => $balance,
            ],
            'invoice' => $orderInvoice instanceof OrderInvoice ? [
                'id' => $orderInvoice->getId(),
                'orderInvoiceId' => $orderInvoice->getId(),
                'realPrice' => $orderInvoice->getRealPrice(),
                'invoiceId' => $orderInvoice->getInvoice()?->getId(),
            ] : null,
            'confirmation' => [
                'confirmed' => $outcome === self::OUTCOME_SUCCESS && $orderType === Order::ORDER_TYPE_SALE,
            ],
            'production' => [
                'pending' => $orderType === Order::ORDER_TYPE_SALE
                    && !in_array($realStatus, ['closed', 'canceled', 'cancelled'], true),
            ],
            'fulfillment' => [
                'pending' => method_exists($this->orderService, 'hasPendingFulfillment')
                    ? (bool) $this->orderService->hasPendingFulfillment($order)
                    : false,
            ],
        ];
    }

    private function resolveBalance(Order $order): float
    {
        $price = (float) ($order->getPrice() ?? 0);
        $paid = 0.0;

        if (!method_exists($order, 'getInvoice')) {
            return $price;
        }

        foreach ($order->getInvoice() as $orderInvoice) {
            $invoice = $orderInvoice->getInvoice();
            if ($invoice === null) {
                continue;
            }
            if ($this->normalizeString($invoice->getStatus()?->getRealStatus()) === 'closed') {
                $paid += (float) ($invoice->getPrice() ?? 0);
            }
        }

        return max(0.0, $price - $paid);
    }
}
