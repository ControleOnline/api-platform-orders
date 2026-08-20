<?php

namespace ControleOnline\Repository;

use ControleOnline\Entity\OrderProductAdjustment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderProductAdjustment>
 */
class OrderProductAdjustmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderProductAdjustment::class);
    }

    public function findByIdempotencyKey(string $key): ?OrderProductAdjustment
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }
}
