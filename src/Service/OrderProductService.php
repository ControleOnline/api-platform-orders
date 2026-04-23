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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;


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

    public function addOrderProduct(Order $order, Product $product, $quantity, $price, ?ProductGroup  $productGroup = null, ?Product $parentProduct = null, ?OrderProduct $orderParentProduct =  null): OrderProduct
    {
        $OProduct = new OrderProduct();
        $OProduct->setOrder($order);
        $OProduct->setParentProduct($parentProduct);
        $OProduct->setOrderProduct($orderParentProduct);
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

    public function addProductsToOrder(Order $order, array $items): Order
    {
        foreach ($items as $item) {
            $product = $this->findProductReference($item['product'] ?? null);
            if (!$product instanceof Product) {
                throw new \InvalidArgumentException('Product not found');
            }

            $quantity = $item['quantity'] ?? 0;
            $price = $product->getPrice();
            $this->addOrderProduct($order, $product, $quantity, $price);
        }

        $this->manager->flush();
        $this->orderService->calculateOrderPrice($order);
        $this->manager->refresh($order);

        return $order;
    }

    public function addProductsToOrderFromContent(
        Order $order,
        ?string $content
    ): Order {
        return $this->addProductsToOrder($order, $this->decodePayload($content));
    }

    public function findOrderProductById(int $id): ?OrderProduct
    {
        return $this->manager->getRepository(OrderProduct::class)->find($id);
    }

    public function prePersist(OrderProduct $orderProduct): void
    {
        $this->guardMarketplaceOrderProductMutation($orderProduct);
    }

    public function preUpdate(OrderProduct $orderProduct): void
    {
        $this->guardMarketplaceOrderProductMutation($orderProduct);
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

    private function removeOrderProductBranch(OrderProduct $orderProduct): void
    {
        foreach ($orderProduct->getOrderProductComponents()->toArray() as $childOrderProduct) {
            $this->removeOrderProductBranch($childOrderProduct);
        }

        foreach ($orderProduct->getOrderProductQueues()->toArray() as $orderProductQueue) {
            $orderProduct->removeOrderProductQueue($orderProductQueue);
            $this->manager->remove($orderProductQueue);
        }

        $parentOrderProduct = $orderProduct->getOrderProduct();
        if ($parentOrderProduct instanceof OrderProduct) {
            $parentOrderProduct->removeOrderProductComponent($orderProduct);
        }

        $this->manager->remove($orderProduct);
    }

    private function cleanupOrderProductRelationsForRemoval(OrderProduct $orderProduct): void
    {
        foreach ($orderProduct->getOrderProductQueues()->toArray() as $orderProductQueue) {
            $orderProduct->removeOrderProductQueue($orderProductQueue);
            $this->manager->remove($orderProductQueue);
        }

        $parentOrderProduct = $orderProduct->getOrderProduct();
        if ($parentOrderProduct instanceof OrderProduct) {
            $parentOrderProduct->removeOrderProductComponent($orderProduct);
        }
    }

    private function replaceSubproducts(OrderProduct $orderProduct, array $subProducts): void
    {
        $existingSubproducts = $this->manager->getRepository(OrderProduct::class)->findBy([
            'orderProduct' => $orderProduct,
        ]);

        foreach ($existingSubproducts as $existingSubproduct) {
            $this->removeOrderProductBranch($existingSubproduct);
        }

        $this->manager->flush();
        $this->manager->refresh($orderProduct);

        $normalizedSubProducts = [];
        foreach ($subProducts as $subproduct) {
            $productId = $this->normalizeReferenceId($subproduct['product'] ?? null);
            $productGroupId = $this->normalizeReferenceId($subproduct['productGroup'] ?? null);
            $quantity = (float) ($subproduct['quantity'] ?? 0);

            if ($productId <= 0 || $productGroupId <= 0 || $quantity <= 0) {
                continue;
            }

            $normalizedSubProducts[sprintf('%d:%d', $productGroupId, $productId)] = [
                'product' => $productId,
                'productGroup' => $productGroupId,
                'quantity' => $quantity,
            ];
        }

        foreach ($normalizedSubProducts as $subproduct) {
            $product = $this->manager->getRepository(Product::class)->find($subproduct['product']);
            $productGroup =  $this->manager->getRepository(ProductGroup::class)->find($subproduct['productGroup']);
            if (!$product instanceof Product || !$productGroup instanceof ProductGroup) {
                continue;
            }
            $this->addSubproduct($orderProduct, $product, $productGroup, $subproduct['quantity']);
        }
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

    private function checkSubproducts(OrderProduct $orderProduct)
    {
        $json = json_decode($this->request->getContent(), true);

        if (isset($json['sub_products'])) {
            $this->replaceSubproducts(
                $orderProduct,
                is_array($json['sub_products']) ? $json['sub_products'] : []
            );
        }
    }

    public function postUpdate(OrderProduct $orderProduct)
    {
        $this->postPersist($orderProduct);
    }
    
    public function postPersist(OrderProduct $orderProduct)
    {
        if (!self::$mainProduct || !$this->request) return;
        self::$mainProduct = false;

        $this->checkSubproducts($orderProduct);
        $this->checkInventory($orderProduct);
        $this->orderProductQueueService->addProductToQueue($orderProduct);
        return $this->calculateProductPrice($orderProduct);
    }

    public function preRemove(OrderProduct $orderProduct)
    {
        $this->guardMarketplaceOrderProductMutation($orderProduct);

        if (!self::$mainProduct) return;
        self::$mainProduct = false;
        $order = $orderProduct->getOrder();
        $this->manager->persist($order->setPrice(0));

        $childOrderProducts = $this->manager->getRepository(OrderProduct::class)->findBy([
            'orderProduct' => $orderProduct,
        ]);

        foreach ($childOrderProducts as $childOrderProduct) {
            $this->removeOrderProductBranch($childOrderProduct);
        }

        $this->cleanupOrderProductRelationsForRemoval($orderProduct);

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

    private function findProductReference(mixed $reference): ?Product
    {
        return $this->manager->getRepository(Product::class)->find(
            $this->normalizeReferenceId($reference)
        );
    }

    private function normalizeReferenceId(mixed $reference): int
    {
        return (int) preg_replace('/\D+/', '', (string) $reference);
    }

    private function decodePayload(?string $content): array
    {
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function guardMarketplaceOrderProductMutation(OrderProduct $orderProduct): void
    {
        $order = $orderProduct->getOrder();
        if (
            !$order instanceof Order
            || !$this->orderService->isMarketplaceIntegrationOrder($order)
            || !$this->isOrderProductMutationRequest()
        ) {
            return;
        }

        throw new BadRequestHttpException(
            'Itens de pedidos de integracao nao podem ser editados diretamente.'
        );
    }

    private function isOrderProductMutationRequest(): bool
    {
        if (!$this->request) {
            return false;
        }

        $method = strtoupper((string) $this->request->getMethod());
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $path = (string) $this->request->getPathInfo();

        return (bool) preg_match('#^/order_products(?:/\d+)?$#', $path)
            || (bool) preg_match('#^/orders/\d+/add-products$#', $path);
    }
}
