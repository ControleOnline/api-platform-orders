<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\OrderProduct;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Doctrine\ORM\QueryBuilder;

class OrderProductService
{
    private $orderProduct;

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $peopleService,
        private OrderService $orderService
    ) {}

    public function afterPersist(OrderProduct $orderProduct)
    {
        $this->calculateProductPrice($orderProduct);
        return $orderProduct;
    }

    public function beforeDelete(OrderProduct $orderProduct)
    {
        $this->orderProduct = $orderProduct;
        $parentProducts = $this->manager->getRepository(OrderProduct::class)->findOneBy([
            'parentProduct'  => $orderProduct,
        ]);
        foreach ($parentProducts as $parentProduct)
            $this->manager->remove($parentProduct);
        $this->manager->flush();
    }

    private function calculateProductPrice(OrderProduct $orderProduct)
    {
        $this->orderProduct = $orderProduct;
        $orderProduct->setPrice($orderProduct->getProduct()->getPrice());
        $orderProduct->setTotal($orderProduct->getPrice() * $orderProduct->getQuantity());
        $this->manager->persist($orderProduct);
        $this->manager->flush();

        return $orderProduct;
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
        if (!$this->orderProduct)
            return;

        $this->orderService->calculateGroupProductPrice($this->orderProduct->getOrder());
        $this->orderService->calculateOrderPrice($this->orderProduct->getOrder());
    }
}
