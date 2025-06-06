<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
as Security;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;


class OrderProductService
{

    private $request;
    private static $mainProduct = true;
    private static $calculateBefore = [];

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $peopleService,
        private OrderService $orderService,
        private RequestStack $requestStack,
        private OrderProductQueueService $orderProductQueueService,
        private InvoiceService $invoiceService
    ) {
        $this->request = $this->requestStack->getCurrentRequest();
    }

    public function addOrderProduct(Order $order, Product $product, $quantity, $price, ?ProductGroup  $productGroup = null, ?Product $parentProduct = null, ?OrderProduct $orderProductParent =  null): OrderProduct
    {
        $OProduct = new OrderProduct();
        $OProduct->setOrder($order);
        $OProduct->setParentProduct($parentProduct);
        $OProduct->setOrderProduct($orderProductParent);
        $OProduct->setProductGroup($productGroup);
        $OProduct->setQuantity($quantity);
        $OProduct->setProduct($product);
        $OProduct->setPrice($price);
        $OProduct->setTotal($price * $quantity);
        $this->checkInventory($OProduct);
        $this->manager->persist($OProduct);
        $this->manager->flush();

        $this->orderProductQueueService->addProductToQueue($OProduct);
        return   $OProduct;
    }

    public function addSubproduct(OrderProduct $orderProduct, Product $product, ProductGroup $productGroup, $quantity)
    {
        $productGroupProduct = $this->manager->getRepository(ProductGroupProduct::class)->findOneBy([
            'productChild' => $product,
            'productGroup' => $productGroup
        ]);

        $OProduct = new OrderProduct();
        $OProduct->setOrder($orderProduct->getOrder());
        $OProduct->setParentProduct($orderProduct->getProduct());
        $OProduct->setOrderProduct($orderProduct);
        $OProduct->setProductGroup($productGroup);
        $OProduct->setQuantity($quantity);
        $OProduct->setProduct($product);
        $OProduct->setPrice($productGroupProduct->getPrice());
        $OProduct->setTotal($productGroupProduct->getPrice() * $quantity);
        $this->checkInventory($OProduct);
        $this->manager->persist($OProduct);
        $this->manager->flush();

        $this->orderProductQueueService->addProductToQueue($OProduct);
    }

    private function checkInventory(OrderProduct &$orderProduct)
    {
        $order = $orderProduct->getOrder();
        $product =  $orderProduct->getProduct();

        if ($order->getOrderType() == 'sale' && !$orderProduct->getOutInventory())
            $orderProduct->setOutInventory($product->getDefaultOutInventory());

        if ($order->getOrderType() == 'purchase' && !$orderProduct->getInInventory())
            $orderProduct->setInInventory($product->getDefaultInInventory());
    }

    public function postPersist(OrderProduct $orderProduct)
    {

        if (!self::$mainProduct || !$this->request) return;
        self::$mainProduct = false;

        $json = json_decode($this->request->getContent(), true);

        if (isset($json['sub_products'])) {
            $subProducts = $json['sub_products'];
            foreach ($subProducts as $subproduct) {
                $product = $this->manager->getRepository(Product::class)->find($subproduct['product']);
                $productGroup =  $this->manager->getRepository(ProductGroup::class)->find($subproduct['productGroup']);
                $this->addSubproduct($orderProduct, $product, $productGroup, $subproduct['quantity']);
            }
        }

        $this->checkInventory($orderProduct);
        $this->orderProductQueueService->addProductToQueue($orderProduct);
        return $this->calculateProductPrice($orderProduct);
    }

    public function preRemove(OrderProduct $orderProduct)
    {

        if (!self::$mainProduct) return;
        self::$mainProduct = false;
        $order = $orderProduct->getOrder();
        $this->manager->persist($order->setPrice(0));

        $parentProducts = $this->manager->getRepository(OrderProduct::class)->findBy([
            'parentProduct'  => $orderProduct->getProduct(),
        ]);

        foreach ($parentProducts as $parentProduct)
            $this->manager->remove($parentProduct);
        $this->manager->flush();

        self::$calculateBefore[] = $order;
    }

    private function calculateProductPrice(OrderProduct $orderProduct)
    {
        $productGroupProduct = $this->manager->getRepository(ProductGroupProduct::class)->findOneBy([
            'productChild' => $orderProduct->getProduct(),
            'productGroup' => $orderProduct->getProductGroup()
        ]) ?: $orderProduct->getProduct();

        $orderProduct->setPrice($productGroupProduct->getPrice());
        $orderProduct->setTotal($productGroupProduct->getPrice() * $orderProduct->getQuantity());
        $this->manager->persist($orderProduct);
        $this->manager->flush();

        $this->orderService->calculateGroupProductPrice($orderProduct->getOrder());
        $this->orderService->calculateOrderPrice($orderProduct->getOrder());

        $this->invoiceService->payOrder($orderProduct->getOrder());
        $this->manager->refresh($orderProduct);

        return $orderProduct;
    }

    public function  securityFilter(QueryBuilder $queryBuilder, $resourceClass = null, $applyTo = null, $rootAlias = null): void
    {
        //$queryBuilder->join(sprintf('%s.order', $rootAlias), 'o');
        //$queryBuilder->andWhere('o.client IN(:companies) OR o.provider IN(:companies)');
        //$companies   = $this->peopleService->getMyCompanies();
        //$queryBuilder->setParameter('companies', $companies);
    }

    public function __destruct()
    {
        foreach (self::$calculateBefore as $order) {
            $this->orderService->calculateGroupProductPrice($order);
            $this->orderService->calculateOrderPrice($order);
        }
    }
}
