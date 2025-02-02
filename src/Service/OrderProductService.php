<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\OrderProduct;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Doctrine\ORM\QueryBuilder;

class OrderProductService
{
    private $order;

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $peopleService,
        private OrderService $orderService
    ) {}

    public function afterPersist(OrderProduct $OrderProduct)
    {
        $this->calculateProductPrice($OrderProduct);
        return $OrderProduct;
    }

    public function beforeDelete(OrderProduct $OrderProduct)
    {
        $this->order = $OrderProduct->getOrder();
    }

    private function calculateProductPrice(OrderProduct $OrderProduct)
    {
        $this->order = $OrderProduct->getOrder();
        $OrderProduct->setPrice($OrderProduct->getProduct()->getPrice());
        $OrderProduct->setTotal($OrderProduct->getPrice() * $OrderProduct->getQuantity());
        $this->manager->persist($OrderProduct);
        $this->manager->flush();

        return $OrderProduct;
    }



    public function  secutiryFilter(QueryBuilder $queryBuilder, $resourceClass = null, $applyTo = null, $rootAlias = null): void
    {
        //$queryBuilder->join(sprintf('%s.order', $rootAlias), 'o');
        //$queryBuilder->andWhere('o.client IN(:companies) OR o.provider IN(:companies)');
        //$companies   = $this->peopleService->getMyCompanies();
        //$queryBuilder->setParameter('companies', $companies);
    }


    public function __destruct()
    {
        if (!$this->order)
            return;
        $this->orderService->calculateOrderPrice($this->order);
        $this->orderService->calculateGroupProductPrice($this->order);
    }
}
