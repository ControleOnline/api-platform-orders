<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;


class OrderProductService
{

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $peopleService,
        private OrderService $orderService,
        private Request $request
    ) {}

    public function afterPersist(OrderProduct $orderProduct)
    {

        $json = json_decode($this->request->getContent(), true);


        foreach ($json->sub_products as $subproduct) {
            $product = $this->manager->getRepository(Product::class)->find($subproduct->product);

            $OProduct = new OrderProduct();
            $OProduct->setOrder($orderProduct->getOrder());
            $OProduct->setParentProduct($orderProduct->getProduct());
            $OProduct->setOrderProduct($orderProduct);
            $OProduct->setProductGroup($this->manager->getRepository(ProductGroup::class)->find($subproduct->productGroup));
            $OProduct->setQuantity($subproduct->quantity);
            $OProduct->setProduct($product);
            $OProduct->setPrice($product->getPrice());
            $OProduct->setTotal($product->getPrice * $subproduct->quantity);
            $this->manager->persist($OProduct);
            $this->manager->flush();
        }


        return $this->calculateProductPrice($orderProduct);
    }

    public function beforeDelete(OrderProduct $orderProduct)
    {
        $parentProducts = $this->manager->getRepository(OrderProduct::class)->findBy([
            'parentProduct'  => $orderProduct->getProduct(),
        ]);
        foreach ($parentProducts as $parentProduct)
            $this->manager->remove($parentProduct);
        $this->manager->flush();

        return $this->calculateProductPrice($orderProduct);
    }

    private function calculateProductPrice(OrderProduct $orderProduct)
    {

        $orderProduct->setPrice($orderProduct->getProduct()->getPrice());
        $orderProduct->setTotal($orderProduct->getPrice() * $orderProduct->getQuantity());
        $this->manager->persist($orderProduct);
        $this->manager->flush();

        $this->orderService->calculateGroupProductPrice($orderProduct->getOrder());
        $this->orderService->calculateOrderPrice($orderProduct->getOrder());

        $this->manager->refresh($orderProduct);

        return $orderProduct;
    }



    public function  secutiryFilter(QueryBuilder $queryBuilder, $resourceClass = null, $applyTo = null, $rootAlias = null): void
    {
        //$queryBuilder->join(sprintf('%s.order', $rootAlias), 'o');
        //$queryBuilder->andWhere('o.client IN(:companies) OR o.provider IN(:companies)');
        //$companies   = $this->peopleService->getMyCompanies();
        //$queryBuilder->setParameter('companies', $companies);
    }
}
