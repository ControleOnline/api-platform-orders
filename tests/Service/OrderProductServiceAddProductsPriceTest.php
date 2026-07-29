<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Service\InvoiceService;
use ControleOnline\Service\OrderProductQueueService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\ProductShowcaseCatalogService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class OrderProductServiceAddProductsPriceTest extends TestCase
{
    public function testPaidComponentsAreConsolidatedBeforeTheOrderTotalIsRecalculated(): void
    {
        // IDs and prices from the reported staging case: order 72835.
        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $this->setEntityId(Order::class, $order, 72835);

        $combo = (new Product())
            ->setProduct('Combo Alpha Gyros')
            ->setPrice(73);
        $this->setEntityId(Product::class, $combo, 1343);

        $paidComponent = (new Product())
            ->setProduct('Maionese Verde - pote 60ml')
            ->setPrice(5.99);
        $this->setEntityId(Product::class, $paidComponent, 1112);

        $paidComponentGroup = (new ProductGroup())
            ->setProductGroup('Molhos extra à parte')
            ->setPriceCalculation('sum');
        $this->setEntityId(ProductGroup::class, $paidComponentGroup, 321);

        $rootOrderProduct = (new OrderProduct())
            ->setOrder($order)
            ->setProduct($combo)
            ->setQuantity(1)
            ->setPrice(73)
            ->setTotal(73);

        $productRepository = $this->createMock(EntityRepository::class);
        $productRepository
            ->method('find')
            ->willReturnCallback(static fn (int $id): ?Product => match ($id) {
                1343 => $combo,
                1112 => $paidComponent,
                default => null,
            });

        $productGroupRepository = $this->createMock(EntityRepository::class);
        $productGroupRepository
            ->method('find')
            ->willReturnCallback(
                static fn (int $id): ?ProductGroup =>
                    321 === $id ? $paidComponentGroup : null,
            );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(
                static fn (string $className): EntityRepository => match ($className) {
                    Product::class => $productRepository,
                    ProductGroup::class => $productGroupRepository,
                    default => throw new \LogicException(sprintf(
                        'Unexpected repository requested: %s',
                        $className,
                    )),
                },
            );
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::once())->method('refresh')->with($order);

        $priceRecalculationSteps = [];
        $orderService = $this->createMock(OrderService::class);
        $orderService
            ->expects(self::once())
            ->method('calculateGroupProductPrice')
            ->with($order)
            ->willReturnCallback(
                static function (Order $targetOrder) use (&$priceRecalculationSteps): Order {
                    $priceRecalculationSteps[] = 'groups';

                    return $targetOrder;
                },
            );
        $orderService
            ->expects(self::once())
            ->method('calculateOrderPrice')
            ->with($order)
            ->willReturnCallback(
                static function (Order $targetOrder) use (&$priceRecalculationSteps): Order {
                    $priceRecalculationSteps[] = 'order';

                    return $targetOrder;
                },
            );

        $catalogService = $this->createMock(ProductShowcaseCatalogService::class);
        $catalogService
            ->expects(self::once())
            ->method('resolveShowcaseForOrder')
            ->with($order, $combo, self::isType('array'))
            ->willReturn(null);

        $service = $this->getMockBuilder(OrderProductService::class)
            ->setConstructorArgs($this->buildConstructorArgs(
                $entityManager,
                $orderService,
                $catalogService,
            ))
            ->onlyMethods(['addOrderProduct', 'addSubproduct'])
            ->getMock();
        $service
            ->expects(self::once())
            ->method('addOrderProduct')
            ->willReturn($rootOrderProduct);
        $service
            ->expects(self::once())
            ->method('addSubproduct')
            ->with($rootOrderProduct, $paidComponent, $paidComponentGroup, 1.0)
            ->willReturn(new OrderProduct());

        $result = $service->addProductsToOrder($order, [[
            'product' => '/products/1343',
            'quantity' => 1,
            'sub_products' => [[
                'product' => 1112,
                'productGroup' => 321,
                'quantity' => 1,
            ]],
        ]]);

        self::assertSame($order, $result);
        self::assertSame(['groups', 'order'], $priceRecalculationSteps);
    }

    /**
     * @return array{
     *     0: EntityManagerInterface,
     *     1: TokenStorageInterface,
     *     2: PeopleService,
     *     3: OrderService,
     *     4: StatusService,
     *     5: RequestStack,
     *     6: OrderProductQueueService,
     *     7: InvoiceService,
     *     8: ProductShowcaseCatalogService
     * }
     */
    private function buildConstructorArgs(
        EntityManagerInterface $entityManager,
        OrderService $orderService,
        ProductShowcaseCatalogService $catalogService,
    ): array {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/orders/72835/add-products', 'PUT'));

        return [
            $entityManager,
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(PeopleService::class),
            $orderService,
            $this->createMock(StatusService::class),
            $requestStack,
            $this->createMock(OrderProductQueueService::class),
            $this->createMock(InvoiceService::class),
            $catalogService,
        ];
    }

    private function setEntityId(string $className, object $entity, int $id): void
    {
        $property = new \ReflectionProperty($className, 'id');
        $property->setValue($entity, $id);
    }
}
