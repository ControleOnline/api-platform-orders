<?php
namespace ControleOnline\Service;

use ControleOnline\Entity\{Order, OrderProduct, Product};
use Doctrine\ORM\EntityManagerInterface;

/** Warm the existing managed detail graph without changing serializer groups or order data. */
final class OrderDetailsReadLoader
{
    public function __construct(private EntityManagerInterface $manager) {}

    public function load(Order $order): void
    {
        if (!$order->getId()) return;
        // Only one collection per query: avoid a files x queues Cartesian product.
        $this->manager->getRepository(Order::class)->createQueryBuilder('detailOrder')
            ->select('detailOrder', 'line', 'product', 'lineStatus', 'productGroup', 'showcaseItem', 'unit', 'productQueue')
            ->leftJoin('detailOrder.orderProducts', 'line')
            ->leftJoin('line.product', 'product')
            ->leftJoin('line.status', 'lineStatus')
            ->leftJoin('line.productGroup', 'productGroup')
            ->leftJoin('line.productShowcaseItem', 'showcaseItem')
            ->leftJoin('product.productUnit', 'unit')
            ->leftJoin('product.queue', 'productQueue')
            ->andWhere('detailOrder.id = :orderId')->setParameter('orderId', $order->getId())
            ->getQuery()->getResult();

        $productIds = [];
        foreach ($order->getOrderProducts() as $line) {
            $id = $line->getProduct()?->getId();
            if ($id) $productIds[$id] = $id;
        }
        if (!$productIds) return;
        $this->manager->getRepository(Product::class)->createQueryBuilder('detailProduct')
            ->select('detailProduct', 'productFile')
            ->leftJoin('detailProduct.productFiles', 'productFile')
            ->andWhere('detailProduct.id IN (:productIds)')->setParameter('productIds', array_values($productIds))
            ->getQuery()->getResult();
        // Keep File proxies: FileMetadataNormalizer reads metadata without binary content.
        $this->manager->getRepository(OrderProduct::class)->createQueryBuilder('detailLine')
            ->select('detailLine', 'productionQueue', 'queueStatus', 'queue')
            ->leftJoin('detailLine.orderProductQueues', 'productionQueue')
            ->leftJoin('productionQueue.status', 'queueStatus')
            ->leftJoin('productionQueue.queue', 'queue')
            ->andWhere('detailLine.order = :detailOrder')->setParameter('detailOrder', $order)
            ->getQuery()->getResult();
    }
}
