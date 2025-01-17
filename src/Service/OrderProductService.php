<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\OrderProduct;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Doctrine\ORM\QueryBuilder;

class OrderProductService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $PeopleService
    ) {
    }

    public function afterPersist(OrderProduct $OrderProduct)
    {
        $order = $OrderProduct->getOrder();

        $sql = 'SELECT SUM(total) as total FROM order_product WHERE order_id = :order_id';
        $connection = $this->manager->getConnection();
        $statement = $connection->prepare($sql);
        $statement->execute(['order_id' =>  $order->getId()]);

        $result = $statement->fetchOne();
        $order->setPrice($result);
        $this->manager->persist($order);
        $this->manager->flush();
        return $order;
    }

    private function  secutiryFilter(QueryBuilder $queryBuilder, $resourceClass = null, $applyTo = null, $rootAlias = null): void
    {
      //$queryBuilder->join(sprintf('%s.order', $rootAlias), 'o');
      //$queryBuilder->andWhere('o.client IN(:companies) OR o.provider IN(:companies)');
      //$companies   = $this->PeopleService->getMyCompanies();
      //$queryBuilder->setParameter('companies', $companies);
    }
}
