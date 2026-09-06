<?php

namespace ControleOnline\Repository;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\OrderProductFulfillment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderProductFulfillment>
 */
class OrderProductFulfillmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderProductFulfillment::class);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?OrderProductFulfillment
    {
        return $this->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }

    /**
     * Sum of completed fulfillment quantities for a root OrderProduct.
     */
    public function sumCompletedQuantity(OrderProduct $orderProduct): float
    {
        $result = $this->createQueryBuilder('f')
            ->select('COALESCE(SUM(f.quantity), 0)')
            ->andWhere('f.orderProduct = :op')
            ->andWhere('f.status = :status')
            ->setParameter('op', $orderProduct)
            ->setParameter('status', OrderProductFulfillment::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) $result;
    }

    /**
     * @return OrderProductFulfillment[]
     */
    public function findCompletedForOrder(Order $order): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.order = :order')
            ->andWhere('f.status = :status')
            ->setParameter('order', $order)
            ->setParameter('status', OrderProductFulfillment::STATUS_COMPLETED)
            ->orderBy('f.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
