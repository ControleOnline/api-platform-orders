<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\Status;
use ControleOnline\Service\InvoiceService;
use ControleOnline\Service\OrderProductQueueService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\ProductShowcaseCatalogService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AllowMockObjectsWithoutExpectations]
class OrderProductServiceConsolidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetOrderProductServiceStaticState();
    }

    public function testAddingEquivalentSimpleProductIncrementsExistingQuantity(): void
    {
        $order = $this->createCartOrder();
        $product = $this->createProduct(55, 10.0);
        $existingOrderProduct = $this->createOrderProduct($order, $product, 1, 10.0);
        $order->addOrderProduct($existingOrderProduct);

        [$service, $persistedOrderProducts] = $this->buildService([
            55 => $product,
        ]);

        $service->addProductsToOrder($order, [
            [
                'product' => '/products/55',
                'quantity' => 1,
            ],
        ]);

        self::assertSame(
            2.0,
            (float) $existingOrderProduct->getQuantity(),
            'An equivalent product must increment the existing order-product quantity.',
        );
        self::assertSame(20.0, (float) $existingOrderProduct->getTotal());
        self::assertCount(
            0,
            $persistedOrderProducts,
            'Consolidating an equivalent product must not persist another root line.',
        );
    }

    public function testAddingDifferentSimpleProductKeepsSeparateRootLine(): void
    {
        $order = $this->createCartOrder();
        $existingProduct = $this->createProduct(55, 10.0);
        $differentProduct = $this->createProduct(77, 7.5);
        $existingOrderProduct = $this->createOrderProduct(
            $order,
            $existingProduct,
            1,
            10.0,
        );
        $order->addOrderProduct($existingOrderProduct);

        [$service, $persistedOrderProducts] = $this->buildService([
            55 => $existingProduct,
            77 => $differentProduct,
        ]);

        $service->addProductsToOrder($order, [
            [
                'product' => '/products/77',
                'quantity' => 1,
            ],
        ]);

        self::assertSame(1.0, (float) $existingOrderProduct->getQuantity());
        self::assertCount(1, $persistedOrderProducts);
        self::assertSame($differentProduct, $persistedOrderProducts[0]->getProduct());
        self::assertSame(1.0, (float) $persistedOrderProducts[0]->getQuantity());
    }

    public function testAddingLoyaltyGiftCommentKeepsSeparateRootLineAndPreservesComment(): void
    {
        $order = $this->createCartOrder();
        $product = $this->createProduct(55, 10.0);
        $existingOrderProduct = $this->createOrderProduct($order, $product, 1, 10.0);
        $order->addOrderProduct($existingOrderProduct);

        [$service, $persistedOrderProducts] = $this->buildService([
            55 => $product,
        ]);

        $service->addProductsToOrder($order, [
            [
                'product' => '/products/55',
                'quantity' => 1,
                'comment' => OrderProductService::LOYALTY_GIFT_COMMENT,
            ],
        ]);

        self::assertSame(1.0, (float) $existingOrderProduct->getQuantity());
        self::assertCount(1, $persistedOrderProducts);
        self::assertSame(
            OrderProductService::LOYALTY_GIFT_COMMENT,
            $persistedOrderProducts[0]->getComment(),
        );
    }

    public function testAddingEquivalentCustomizedProductIncrementsTheWholeTree(): void
    {
        $order = $this->createCartOrder();
        $rootProduct = $this->createProduct(55, 10.0);
        $childProduct = $this->createProduct(102, 2.5);
        $productGroup = $this->createProductGroup(50);
        $existingRoot = $this->createOrderProduct($order, $rootProduct, 1, 10.0);
        $existingChild = $this->createOrderProduct($order, $childProduct, 1, 2.5);
        $existingChild->setParentProduct($rootProduct);
        $existingChild->setProductGroup($productGroup);
        $existingRoot->addOrderProductComponent($existingChild);
        $order->addOrderProduct($existingRoot);
        $order->addOrderProduct($existingChild);

        [$service, $persistedOrderProducts] = $this->buildService(
            [55 => $rootProduct, 102 => $childProduct],
            [50 => $productGroup],
        );

        $service->addProductsToOrder($order, [[
            'product' => '/products/55',
            'quantity' => 1,
            'sub_products' => [[
                'product' => '/products/102',
                'productGroup' => '/product_groups/50',
                'quantity' => 1,
            ]],
        ]]);

        self::assertSame(2.0, (float) $existingRoot->getQuantity());
        self::assertSame(20.0, (float) $existingRoot->getTotal());
        self::assertSame(2.0, (float) $existingChild->getQuantity());
        self::assertSame(5.0, (float) $existingChild->getTotal());
        self::assertCount(
            0,
            $persistedOrderProducts,
            'An equivalent customization must reuse the persisted root and children.',
        );
    }

    public function testAddingDifferentCustomizedProductPersistsAnotherTree(): void
    {
        $order = $this->createCartOrder();
        $rootProduct = $this->createProduct(55, 10.0);
        $firstChildProduct = $this->createProduct(102, 2.5);
        $secondChildProduct = $this->createProduct(103, 3.0);
        $productGroup = $this->createProductGroup(50);
        $existingRoot = $this->createOrderProduct($order, $rootProduct, 1, 10.0);
        $existingChild = $this->createOrderProduct($order, $firstChildProduct, 1, 2.5);
        $existingChild->setParentProduct($rootProduct);
        $existingChild->setProductGroup($productGroup);
        $existingRoot->addOrderProductComponent($existingChild);
        $order->addOrderProduct($existingRoot);
        $order->addOrderProduct($existingChild);
        $newLink = $this->createProductGroupProduct(
            $rootProduct,
            $secondChildProduct,
            $productGroup,
            3.0,
        );

        [$service, $persistedOrderProducts] = $this->buildService(
            [
                55 => $rootProduct,
                102 => $firstChildProduct,
                103 => $secondChildProduct,
            ],
            [50 => $productGroup],
            [$newLink],
        );

        $service->addProductsToOrder($order, [[
            'product' => '/products/55',
            'quantity' => 1,
            'sub_products' => [[
                'product' => '/products/103',
                'productGroup' => '/product_groups/50',
                'quantity' => 1,
            ]],
        ]]);

        self::assertSame(1.0, (float) $existingRoot->getQuantity());
        self::assertSame(1.0, (float) $existingChild->getQuantity());
        self::assertCount(2, $persistedOrderProducts);
        self::assertSame($rootProduct, $persistedOrderProducts[0]->getProduct());
        self::assertSame($secondChildProduct, $persistedOrderProducts[1]->getProduct());
        self::assertSame($persistedOrderProducts[0], $persistedOrderProducts[1]->getOrderProduct());
    }

    public function testSameCustomizationWithDifferentComponentQuantityStaysSeparate(): void
    {
        $order = $this->createCartOrder();
        $rootProduct = $this->createProduct(55, 10.0);
        $childProduct = $this->createProduct(102, 2.5);
        $productGroup = $this->createProductGroup(50);
        $existingRoot = $this->createOrderProduct($order, $rootProduct, 1, 10.0);
        $existingChild = $this->createOrderProduct($order, $childProduct, 1, 2.5);
        $existingChild->setParentProduct($rootProduct);
        $existingChild->setProductGroup($productGroup);
        $existingRoot->addOrderProductComponent($existingChild);
        $order->addOrderProduct($existingRoot);
        $order->addOrderProduct($existingChild);
        $link = $this->createProductGroupProduct(
            $rootProduct,
            $childProduct,
            $productGroup,
            2.5,
        );

        [$service, $persistedOrderProducts] = $this->buildService(
            [55 => $rootProduct, 102 => $childProduct],
            [50 => $productGroup],
            [$link],
        );

        $service->addProductsToOrder($order, [[
            'product' => '/products/55',
            'quantity' => 1,
            'sub_products' => [[
                'product' => '/products/102',
                'productGroup' => '/product_groups/50',
                'quantity' => 2,
            ]],
        ]]);

        self::assertSame(1.0, (float) $existingRoot->getQuantity());
        self::assertSame(1.0, (float) $existingChild->getQuantity());
        self::assertCount(2, $persistedOrderProducts);
        self::assertSame(2.0, (float) $persistedOrderProducts[1]->getQuantity());
    }

    /**
     * @param array<int, Product> $productsById
     * @param array<int, ProductGroup> $productGroupsById
     * @param list<ProductGroupProduct> $productGroupProducts
     *
     * @return array{0: OrderProductService, 1: \ArrayObject<int, OrderProduct>}
     */
    private function buildService(
        array $productsById,
        array $productGroupsById = [],
        array $productGroupProducts = [],
    ): array
    {
        $productRepository = $this->createMock(EntityRepository::class);
        $productRepository
            ->method('find')
            ->willReturnCallback(
                static fn (int $id): ?Product => $productsById[$id] ?? null,
            );

        $productGroupRepository = $this->createMock(EntityRepository::class);
        $productGroupRepository
            ->method('find')
            ->willReturnCallback(
                static fn (int $id): ?ProductGroup => $productGroupsById[$id] ?? null,
            );

        $productGroupProductRepository = $this->createMock(EntityRepository::class);
        $productGroupProductRepository
            ->method('findOneBy')
            ->willReturnCallback(
                static function (array $criteria) use ($productGroupProducts): ?ProductGroupProduct {
                    foreach ($productGroupProducts as $productGroupProduct) {
                        if (
                            isset($criteria['product'])
                            && $criteria['product'] !== $productGroupProduct->getProduct()
                        ) {
                            continue;
                        }
                        if (
                            ($criteria['productChild'] ?? null)
                            !== $productGroupProduct->getProductChild()
                            || ($criteria['productGroup'] ?? null)
                            !== $productGroupProduct->getProductGroup()
                        ) {
                            continue;
                        }

                        return $productGroupProduct;
                    }

                    return null;
                },
            );

        $persistedOrderProducts = new \ArrayObject();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(
                static fn (string $className): EntityRepository => match ($className) {
                    Product::class => $productRepository,
                    ProductGroup::class => $productGroupRepository,
                    ProductGroupProduct::class => $productGroupProductRepository,
                    default => throw new \LogicException('Unexpected repository: ' . $className),
                },
            );
        $entityManager
            ->method('persist')
            ->willReturnCallback(
                static function (object $entity) use ($persistedOrderProducts): void {
                    if ($entity instanceof OrderProduct) {
                        $persistedOrderProducts->append($entity);
                    }
                },
            );

        $statusService = $this->createMock(StatusService::class);
        $statusService
            ->method('discoveryStatus')
            ->with('open', 'open', 'order_product')
            ->willReturn(new Status());

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/orders/78112/add-products', 'PUT'));

        $service = new OrderProductService(
            $entityManager,
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(PeopleService::class),
            $this->createMock(OrderService::class),
            $statusService,
            $requestStack,
            $this->createMock(OrderProductQueueService::class),
            $this->createMock(InvoiceService::class),
            $this->createMock(ProductShowcaseCatalogService::class),
        );

        return [$service, $persistedOrderProducts];
    }

    private function createCartOrder(): Order
    {
        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_CART);

        return $order;
    }

    private function createProduct(int $id, float $price): Product
    {
        $product = new Product();
        $product->setId($id);
        $product->setPrice($price);

        return $product;
    }

    private function createProductGroup(int $id): ProductGroup
    {
        $productGroup = new ProductGroup();
        $idProperty = new \ReflectionProperty(ProductGroup::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($productGroup, $id);

        return $productGroup;
    }

    private function createProductGroupProduct(
        Product $rootProduct,
        Product $childProduct,
        ProductGroup $productGroup,
        float $price,
    ): ProductGroupProduct {
        $productGroupProduct = new ProductGroupProduct();
        $productGroupProduct->setProduct($rootProduct);
        $productGroupProduct->setProductChild($childProduct);
        $productGroupProduct->setProductGroup($productGroup);
        $productGroupProduct->setPrice($price);

        return $productGroupProduct;
    }

    private function createOrderProduct(
        Order $order,
        Product $product,
        float $quantity,
        float $price,
    ): OrderProduct {
        $orderProduct = new OrderProduct();
        $orderProduct->setOrder($order);
        $orderProduct->setProduct($product);
        $orderProduct->setStatus(new Status());
        $orderProduct->setQuantity($quantity);
        $orderProduct->setPrice($price);
        $orderProduct->setTotal($price * $quantity);

        return $orderProduct;
    }

    private function resetOrderProductServiceStaticState(): void
    {
        foreach (['mainProduct' => true, 'calculateBefore' => []] as $propertyName => $value) {
            $property = new \ReflectionProperty(OrderProductService::class, $propertyName);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }
}
