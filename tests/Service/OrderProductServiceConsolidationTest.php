<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\Status;
use ControleOnline\Service\InvoiceService;
use ControleOnline\Service\OrderProductQueueService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
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

    /**
     * @param array<int, Product> $productsById
     *
     * @return array{0: OrderProductService, 1: \ArrayObject<int, OrderProduct>}
     */
    private function buildService(array $productsById): array
    {
        $productRepository = $this->createMock(EntityRepository::class);
        $productRepository
            ->method('find')
            ->willReturnCallback(
                static fn (int $id): ?Product => $productsById[$id] ?? null,
            );

        $persistedOrderProducts = new \ArrayObject();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(
                static fn (string $className): EntityRepository => match ($className) {
                    Product::class => $productRepository,
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
