<?php

namespace ControleOnline\Repository;

use ControleOnline\Entity\Order;
use ControleOnline\Service\PeopleService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;


class OrderRepository extends ServiceEntityRepository
{
  public function __construct(
    private PeopleService $peopleService,

    ManagerRegistry $registry
  ) {
    parent::__construct($registry, Order::class);
  }
}
