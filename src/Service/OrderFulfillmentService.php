<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\OrderProductFulfillment;
use ControleOnline\Entity\People;
use ControleOnline\Repository\OrderProductFulfillmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Canonical transactional fulfillment ledger.
 * Production/queue ready never writes fulfillment; only explicit actions do.
 */
class OrderFulfillmentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderProductFulfillmentRepository $fulfillmentRepository,
    ) {
    }

    /**
     * Execute an idempotent fulfillment action for a root OrderProduct.
     *
     * @param array{
     *   orderProductId: int,
     *   action: string,
     *   quantity?: float|int,
     *   idempotencyKey: string,
     *   deviceOrigin?: string|null,
     *   reason?: string|null,
     * } $payload
     */
    public function execute(array $payload, ?People $actor, bool $isClient = false): OrderProductFulfillment
    {
        if ($isClient) {
            throw new AccessDeniedHttpException('Clients cannot complete fulfillment.');
        }

        $orderProductId = (int) ($payload['orderProductId'] ?? 0);
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        $idempotencyKey = trim((string) ($payload['idempotencyKey'] ?? ''));
        $quantity = (float) ($payload['quantity'] ?? 1);
        $deviceOrigin = isset($payload['deviceOrigin']) ? (string) $payload['deviceOrigin'] : null;
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;

        if ($orderProductId <= 0) {
            throw new BadRequestHttpException('orderProductId is required.');
        }
        if ($idempotencyKey === '') {
            throw new BadRequestHttpException('idempotencyKey is required.');
        }
        if (!in_array($action, OrderProductFulfillment::ALLOWED_ACTIONS, true)) {
            throw new BadRequestHttpException(sprintf(
                'Invalid action. Allowed: %s',
                implode(', ', OrderProductFulfillment::ALLOWED_ACTIONS)
            ));
        }
        if ($quantity <= 0) {
            throw new BadRequestHttpException('quantity must be greater than zero.');
        }

        // Idempotent replay: same key returns existing ledger entry.
        $existing = $this->fulfillmentRepository->findByIdempotencyKey($idempotencyKey);
        if ($existing instanceof OrderProductFulfillment) {
            return $existing;
        }

        /** @var OrderProduct|null $orderProduct */
        $orderProduct = $this->entityManager->getRepository(OrderProduct::class)->find($orderProductId);
        if (!$orderProduct instanceof OrderProduct) {
            throw new NotFoundHttpException('Order product not found.');
        }

        // Only root items are fulfillable; customizations follow the root.
        if ($orderProduct->getOrderProduct() !== null) {
            throw new BadRequestHttpException(
                'Fulfillment applies only to root OrderProduct; customizations follow the parent.'
            );
        }

        $order = $orderProduct->getOrder();
        if (!$order instanceof Order) {
            throw new BadRequestHttpException('Order product has no order.');
        }

        $confirmedQty = (float) $orderProduct->getQuantity();
        $alreadyFulfilled = $this->fulfillmentRepository->sumCompletedQuantity($orderProduct);
        $remaining = $confirmedQty - $alreadyFulfilled;

        if ($quantity > $remaining + 1e-9) {
            throw new BadRequestHttpException(sprintf(
                'Quantity %.4f exceeds remaining confirmed quantity %.4f (confirmed=%.4f, fulfilled=%.4f).',
                $quantity,
                max(0, $remaining),
                $confirmedQty,
                $alreadyFulfilled
            ));
        }

        $entry = new OrderProductFulfillment();
        $entry->setOrder($order);
        $entry->setOrderProduct($orderProduct);
        $entry->setAction($action);
        $entry->setQuantity($quantity);
        $entry->setStatus(OrderProductFulfillment::STATUS_COMPLETED);
        $entry->setIdempotencyKey($idempotencyKey);
        $entry->setActor($actor);
        $entry->setDeviceOrigin($deviceOrigin);
        $entry->setReason($reason);

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    /**
     * Summary of fulfillment for an order (items with/without queue still appear).
     *
     * @return array<int, array{orderProductId: int, confirmed: float, fulfilled: float, remaining: float}>
     */
    public function summarizeOrder(Order $order): array
    {
        $products = $order->getOrderProducts() ?? [];
        $summary = [];

        foreach ($products as $op) {
            if (!$op instanceof OrderProduct) {
                continue;
            }
            // Root only
            if ($op->getOrderProduct() !== null) {
                continue;
            }
            $confirmed = (float) $op->getQuantity();
            $fulfilled = $this->fulfillmentRepository->sumCompletedQuantity($op);
            $summary[] = [
                'orderProductId' => $op->getId(),
                'confirmed' => $confirmed,
                'fulfilled' => $fulfilled,
                'remaining' => max(0.0, $confirmed - $fulfilled),
            ];
        }

        return $summary;
    }
}
