<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\OrderProductAdjustment;
use ControleOnline\Entity\People;
use ControleOnline\Repository\OrderProductAdjustmentRepository;
use ControleOnline\Repository\OrderProductFulfillmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Explicit audited quantity adjustment for confirmed (sale) order products.
 *
 * Free PUT/DELETE remains blocked by OrderProductServiceHelpers::guardDirectOrderProductMutation.
 * Client / Shop / Totem actors must not call commit.
 */
class OrderProductAdjustmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderProductAdjustmentRepository $adjustmentRepository,
        private readonly ?OrderProductFulfillmentRepository $fulfillmentRepository = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array{
     *   orderProductId: int,
     *   quantityAfter: float|int,
     *   reason: string,
     *   idempotencyKey: string,
     *   deviceId?: int|null
     * } $payload
     *
     * @return array{ok: bool, errno?: int, errmsg?: string, preview?: array, entry?: OrderProductAdjustment, replayed?: bool}
     */
    public function preview(array $payload, ?People $actor = null, bool $denyClient = true): array
    {
        return $this->run($payload, $actor, $denyClient, commit: false);
    }

    /**
     * @param array{
     *   orderProductId: int,
     *   quantityAfter: float|int,
     *   reason: string,
     *   idempotencyKey: string,
     *   deviceId?: int|null
     * } $payload
     *
     * @return array{ok: bool, errno?: int, errmsg?: string, preview?: array, entry?: OrderProductAdjustment, replayed?: bool}
     */
    public function commit(array $payload, ?People $actor = null, bool $denyClient = true): array
    {
        return $this->run($payload, $actor, $denyClient, commit: true);
    }

    private function run(array $payload, ?People $actor, bool $denyClient, bool $commit): array
    {
        $orderProductId = (int) ($payload['orderProductId'] ?? 0);
        $quantityAfter = (float) ($payload['quantityAfter'] ?? -1);
        $reason = trim((string) ($payload['reason'] ?? ''));
        $idempotencyKey = trim((string) ($payload['idempotencyKey'] ?? ''));
        $deviceId = isset($payload['deviceId']) ? (int) $payload['deviceId'] : null;

        if ($orderProductId <= 0) {
            return $this->error(20001, 'orderProductId is required.');
        }
        if ($quantityAfter < 0) {
            return $this->error(20002, 'quantityAfter must be >= 0.');
        }
        if ($reason === '') {
            return $this->error(20003, 'reason is required for audited adjustments.');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            return $this->error(20004, 'idempotencyKey is required (max 128 chars).');
        }

        $existing = $this->adjustmentRepository->findByIdempotencyKey($idempotencyKey);
        if ($existing instanceof OrderProductAdjustment && $existing->getStatus() === OrderProductAdjustment::STATUS_COMMITTED) {
            return [
                'ok' => true,
                'entry' => $existing,
                'preview' => $this->snapshotFromEntry($existing),
                'replayed' => true,
            ];
        }

        /** @var OrderProduct|null $orderProduct */
        $orderProduct = $this->em->getRepository(OrderProduct::class)->find($orderProductId);
        if (!$orderProduct instanceof OrderProduct) {
            return $this->error(20005, 'Order product not found.');
        }

        if ($orderProduct->getParentProduct() !== null) {
            return $this->error(20006, 'Adjustments apply only to root order products.');
        }

        $order = $orderProduct->getOrder();
        if (!$order instanceof Order) {
            return $this->error(20007, 'Order not found.');
        }

        $orderType = strtolower(trim((string) $order->getOrderType()));
        if ($orderType === OrderService::ORDER_TYPE_CART) {
            return $this->error(20008, 'Cart orders remain editable via the normal cart flow; use adjustment only on confirmed sales.');
        }

        if ($denyClient && $actor instanceof People && $this->isClientOnlyActor($actor, $order)) {
            return $this->error(20009, 'Client is not allowed to execute post-confirmation adjustments.');
        }

        $quantityBefore = (float) $orderProduct->getQuantity();
        $fulfilled = $this->sumFulfilled($orderProduct);

        if ($quantityAfter + 1e-9 < $fulfilled) {
            return $this->error(
                20010,
                sprintf(
                    'Cannot reduce below already fulfilled quantity (fulfilled=%.4f, requested=%.4f).',
                    $fulfilled,
                    $quantityAfter
                )
            );
        }

        $preview = [
            'orderProductId' => $orderProduct->getId(),
            'orderId' => $order->getId(),
            'orderType' => $order->getOrderType(),
            'quantityBefore' => $quantityBefore,
            'quantityAfter' => $quantityAfter,
            'delta' => $quantityAfter - $quantityBefore,
            'fulfilled' => $fulfilled,
            'remainingAfter' => max(0.0, $quantityAfter - $fulfilled),
            'hasProductionQueue' => $this->hasProductionQueue($orderProduct),
            'reason' => $reason,
        ];

        if (!$commit) {
            return ['ok' => true, 'preview' => $preview, 'replayed' => false];
        }

        // Revalidate under transaction before mutating.
        $this->em->beginTransaction();
        try {
            $this->em->refresh($orderProduct);
            $quantityBefore = (float) $orderProduct->getQuantity();
            $fulfilled = $this->sumFulfilled($orderProduct);

            if ($quantityAfter + 1e-9 < $fulfilled) {
                $this->em->rollback();

                return $this->error(
                    20010,
                    sprintf(
                        'Cannot reduce below already fulfilled quantity (fulfilled=%.4f, requested=%.4f).',
                        $fulfilled,
                        $quantityAfter
                    )
                );
            }

            $race = $this->adjustmentRepository->findByIdempotencyKey($idempotencyKey);
            if ($race instanceof OrderProductAdjustment && $race->getStatus() === OrderProductAdjustment::STATUS_COMMITTED) {
                $this->em->rollback();

                return [
                    'ok' => true,
                    'entry' => $race,
                    'preview' => $this->snapshotFromEntry($race),
                    'replayed' => true,
                ];
            }

            $orderProduct->setQuantity($quantityAfter);
            $this->em->persist($orderProduct);

            $entry = new OrderProductAdjustment();
            $entry->setOrder($order);
            $entry->setOrderProduct($orderProduct);
            $entry->setQuantityBefore($quantityBefore);
            $entry->setQuantityAfter($quantityAfter);
            $entry->setFulfilledAtAdjustment($fulfilled);
            $entry->setReason($reason);
            $entry->setStatus(OrderProductAdjustment::STATUS_COMMITTED);
            $entry->setIdempotencyKey($idempotencyKey);
            $entry->setActor($actor);
            $entry->setDeviceId($deviceId > 0 ? $deviceId : null);
            $entry->setSnapshot($preview);

            $this->em->persist($entry);
            $this->em->flush();
            $this->em->commit();

            return [
                'ok' => true,
                'entry' => $entry,
                'preview' => $preview,
                'replayed' => false,
            ];
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            $this->logger?->error('Order product adjustment commit failed.', [
                'orderProductId' => $orderProductId,
                'error' => $e->getMessage(),
            ]);

            return $this->error(20011, 'Adjustment commit failed.');
        }
    }

    private function sumFulfilled(OrderProduct $orderProduct): float
    {
        if ($this->fulfillmentRepository === null) {
            return 0.0;
        }

        try {
            return $this->fulfillmentRepository->sumCompletedQuantity($orderProduct);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function hasProductionQueue(OrderProduct $orderProduct): bool
    {
        if (!method_exists($orderProduct, 'getOrderProductQueues')) {
            return false;
        }
        $queues = $orderProduct->getOrderProductQueues();
        if ($queues === null) {
            return false;
        }
        if (is_countable($queues)) {
            return count($queues) > 0;
        }

        return method_exists($queues, 'isEmpty') ? !$queues->isEmpty() : false;
    }

    private function isClientOnlyActor(People $actor, Order $order): bool
    {
        $client = method_exists($order, 'getClient') ? $order->getClient() : null;
        if (!$client instanceof People || $actor->getId() !== $client->getId()) {
            return false;
        }
        $provider = method_exists($order, 'getProvider') ? $order->getProvider() : null;
        if ($provider instanceof People && $actor->getId() === $provider->getId()) {
            return false;
        }

        return true;
    }

    private function snapshotFromEntry(OrderProductAdjustment $entry): array
    {
        return $entry->getSnapshot() ?? [
            'orderProductId' => $entry->getOrderProduct()?->getId(),
            'orderId' => $entry->getOrder()?->getId(),
            'quantityBefore' => $entry->getQuantityBefore(),
            'quantityAfter' => $entry->getQuantityAfter(),
            'fulfilled' => $entry->getFulfilledAtAdjustment(),
            'reason' => $entry->getReason(),
        ];
    }

    private function error(int $errno, string $errmsg): array
    {
        return ['ok' => false, 'errno' => $errno, 'errmsg' => $errmsg];
    }
}
