<?php

namespace ControleOnline\Repository;

use ControleOnline\Entity\OrderQrConsume;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderQrConsume>
 */
class OrderQrConsumeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderQrConsume::class);
    }

    public function findOneByIdempotencyKey(string $idempotencyKey): ?OrderQrConsume
    {
        return $this->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }
}
