<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Inventory;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\OrderProductQueueService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\StatusService;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\SerializerInterface;

#[AllowMockObjectsWithoutExpectations]
class OrderServiceTest extends TestCase
{
    public function testCreateOrderStartsPosFlowAsCart(): void
    {
        $receiver = $this->createMock(People::class);
        $payer = $this->createMock(People::class);
        $draftStatus = $this->createMock(Status::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $statusService = $this->createMock(StatusService::class);

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('open', 'open', 'order')
            ->willReturn($draftStatus);

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($receiver, $payer, $draftStatus): bool {
                return $entity instanceof Order
                    && $entity->getProvider() === $receiver
                    && $entity->getClient() === $payer
                    && $entity->getPayer() === $payer
                    && $entity->getStatus() === $draftStatus
                    && $entity->getOrderType() === OrderService::ORDER_TYPE_CART
                    && $entity->getApp() === 'POS';
            }));

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service = $this->buildService('/orders', $entityManager, $statusService);

        $order = $service->createOrder($receiver, $payer, 'POS');

        self::assertSame(OrderService::ORDER_TYPE_CART, $order->getOrderType());
        self::assertSame($draftStatus, $order->getStatus());
    }

    public function testCreateOrderStartsMarketplaceFlowAsSale(): void
    {
        $receiver = $this->createMock(People::class);
        $payer = $this->createMock(People::class);
        $pendingStatus = $this->createMock(Status::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $statusService = $this->createMock(StatusService::class);

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('pending', 'waiting payment', 'order')
            ->willReturn($pendingStatus);

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($pendingStatus): bool {
                return $entity instanceof Order
                    && $entity->getStatus() === $pendingStatus
                    && $entity->getOrderType() === OrderService::ORDER_TYPE_SALE
                    && $entity->getApp() === Order::APP_IFOOD;
            }));

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service = $this->buildService('/orders', $entityManager, $statusService);

        $order = $service->createOrder($receiver, $payer, Order::APP_IFOOD);

        self::assertSame(OrderService::ORDER_TYPE_SALE, $order->getOrderType());
        self::assertSame($pendingStatus, $order->getStatus());
    }

    public function testNormalizeDraftCartOrderConvertsLegacyQuoteToCart(): void
    {
        $queueService = $this->createMock(OrderProductQueueService::class);
        $order = new Order();
        $order->setApp('SHOP');
        $order->setOrderType(OrderService::ORDER_TYPE_QUOTE);

        $queueService
            ->expects(self::once())
            ->method('syncByOrderStatus')
            ->with($order);

        $service = $this->buildService('/orders', null, null, $queueService);

        self::assertTrue($service->normalizeDraftCartOrder($order));
        self::assertSame(OrderService::ORDER_TYPE_CART, $order->getOrderType());
    }

    public function testConvertDraftOrderToSalePromotesDraftAndMaterializesQueues(): void
    {
        $queueService = $this->createMock(OrderProductQueueService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $defaultOutInventory = $this->createMock(Inventory::class);
        $product = $this->createMock(Product::class);
        $product
            ->method('getDefaultOutInventory')
            ->willReturn($defaultOutInventory);

        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_CART);

        $orderProduct = new OrderProduct();
        $orderProduct->setOrder($order);
        $orderProduct->setProduct($product);
        $orderProduct->setQuantity(1);
        $order->addOrderProduct($orderProduct);

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($orderProduct, $defaultOutInventory): bool {
                return $entity === $orderProduct
                    && $entity->getOutInventory() === $defaultOutInventory;
            }));

        $queueService
            ->expects(self::once())
            ->method('ensureOrderQueueEntries')
            ->with($order);

        $service = $this->buildService('/orders', $entityManager, null, $queueService);

        self::assertTrue($service->convertDraftOrderToSale($order));
        self::assertSame(OrderService::ORDER_TYPE_SALE, $order->getOrderType());
        self::assertSame($defaultOutInventory, $orderProduct->getOutInventory());
    }

    public function testResolvePostPaymentStatusPromotesCartToSaleBeforeClosedResolution(): void
    {
        $statusService = $this->createMock(StatusService::class);
        $queueService = $this->createMock(OrderProductQueueService::class);
        $closedStatus = $this->createMock(Status::class);
        $closedStatus
            ->method('getRealStatus')
            ->willReturn('closed');

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('closed', 'closed', 'order')
            ->willReturn($closedStatus);

        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $order->setStatus($this->createStatusMock('open'));

        $queueService
            ->expects(self::once())
            ->method('ensureOrderQueueEntries')
            ->with($order);

        $service = $this->buildService('/orders', null, $statusService, $queueService);

        self::assertSame($closedStatus, $service->resolvePostPaymentStatus($order));
        self::assertSame(OrderService::ORDER_TYPE_SALE, $order->getOrderType());
    }

    public function testResolvePostPaymentStatusPromotesCartToSaleBeforePreparingResolution(): void
    {
        $statusService = $this->createMock(StatusService::class);
        $queueService = $this->createMock(OrderProductQueueService::class);
        $preparingStatus = $this->createMock(Status::class);
        $preparingStatus
            ->method('getRealStatus')
            ->willReturn('preparing');

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('open', 'preparing', 'order')
            ->willReturn($preparingStatus);

        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $order->setStatus($this->createStatusMock('open'));
        $order->setAddressDestination($this->createMock(Address::class));

        $queueService
            ->expects(self::once())
            ->method('ensureOrderQueueEntries')
            ->with($order);

        $service = $this->buildService('/orders', null, $statusService, $queueService);

        self::assertSame($preparingStatus, $service->resolvePostPaymentStatus($order));
        self::assertSame(OrderService::ORDER_TYPE_SALE, $order->getOrderType());
    }

    public function testUpdateOrderFromPayloadRejectsStatusChanges(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer
            ->expects(self::never())
            ->method('deserialize');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $queueService = $this->createMock(OrderProductQueueService::class);
        $service = $this->buildService('/orders/901', $entityManager, null, $queueService, null, [], [], null, [], $serializer);

        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $order->setStatus($this->createStatusEntity(10, 'open'));

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Status do pedido nao pode ser alterado por PUT. Use as acoes do pedido.');

        $service->updateOrderFromPayload($order, [
            'status' => '/statuses/11',
        ]);
    }

    public function testUpdateOrderFromPayloadPromotesCartToSaleAndDispatchesCreationEvent(): void
    {
        $provider = new People();
        $this->setEntityId(People::class, $provider, 71);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer
            ->expects(self::once())
            ->method('deserialize')
            ->willReturnCallback(static function (string $json, string $class, string $format, array $context): Order {
                $order = $context['object_to_populate'];
                $order->setComments('Mesa 4');

                return $order;
            });

        $deviceConfigRepository = $this->createMock(EntityRepository::class);
        $deviceConfigRepository
            ->expects(self::exactly(2))
            ->method('findBy')
            ->with(['people' => $provider])
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(2))
            ->method('getRepository')
            ->with(DeviceConfig::class)
            ->willReturn($deviceConfigRepository);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (mixed $entity): bool {
                return $entity instanceof Order
                    && $entity->getOrderType() === OrderService::ORDER_TYPE_SALE
                    && $entity->getComments() === 'Mesa 4';
            }));
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $queueService = $this->createMock(OrderProductQueueService::class);
        $queueService
            ->expects(self::once())
            ->method('ensureOrderQueueEntries')
            ->with(self::callback(static function (Order $order): bool {
                return $order->getOrderType() === OrderService::ORDER_TYPE_SALE;
            }));

        $service = $this->buildService('/orders/902', $entityManager, null, $queueService, null, [], [], null, [], $serializer);

        $order = new Order();
        $order->setApp('POS');
        $order->setProvider($provider);
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $order->setStatus($this->createStatusEntity(10, 'open'));
        $this->setEntityId(Order::class, $order, 902);

        $updatedOrder = $service->updateOrderFromPayload($order, [
            'orderType' => 'sale',
            'comments' => 'Mesa 4',
        ]);

        self::assertSame($order, $updatedOrder);
        self::assertSame(OrderService::ORDER_TYPE_SALE, $order->getOrderType());
        self::assertSame('Mesa 4', $order->getComments());
    }

    public function testUpdateOrderFromPayloadNormalizesSaleBackToCartWhenAllowed(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer
            ->expects(self::once())
            ->method('deserialize')
            ->willReturnCallback(static function (string $json, string $class, string $format, array $context): Order {
                $order = $context['object_to_populate'];
                $order->setComments('Reclassificado');

                return $order;
            });

        $queueService = $this->createMock(OrderProductQueueService::class);
        $queueService
            ->expects(self::once())
            ->method('syncByOrderStatus')
            ->with(self::callback(static function (Order $order): bool {
                return $order->getOrderType() === OrderService::ORDER_TYPE_CART;
            }));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (mixed $entity): bool {
                return $entity instanceof Order
                    && $entity->getOrderType() === OrderService::ORDER_TYPE_CART
                    && $entity->getComments() === 'Reclassificado';
            }));
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service = $this->buildService('/orders/903', $entityManager, null, $queueService, null, [], [], null, [], $serializer);

        $order = new Order();
        $order->setApp('POS');
        $order->setOrderType(OrderService::ORDER_TYPE_SALE);
        $order->setStatus($this->createStatusEntity(10, 'open'));
        $this->setEntityId(Order::class, $order, 903);

        $updatedOrder = $service->updateOrderFromPayload($order, [
            'orderType' => 'cart',
            'comments' => 'Reclassificado',
        ]);

        self::assertSame($order, $updatedOrder);
        self::assertSame(OrderService::ORDER_TYPE_CART, $order->getOrderType());
        self::assertSame('Reclassificado', $order->getComments());
    }

    public function testCalculateGroupProductPriceConsolidatesGroupRulesIntoParentTotal(): void
    {
        $order = new Order();
        $this->setEntityId(Order::class, $order, 72320);

        $boundParameters = [];
        $statement = $this->createMock(Statement::class);
        $statement
            ->expects(self::exactly(3))
            ->method('bindValue')
            ->willReturnCallback(static function (string $parameter, mixed $value) use (&$boundParameters): void {
                $boundParameters[$parameter] = $value;
            });
        $statement
            ->expects(self::once())
            ->method('executeStatement');

        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['prepare'])
            ->getMock();
        $connection
            ->expects(self::once())
            ->method('prepare')
            ->with(self::callback(static function (string $sql): bool {
                return str_contains($sql, 'P.price + IFNULL(parent_prices.extra_price, 0)')
                    && str_contains($sql, 'SUM(grouped_prices.group_price) AS extra_price')
                    && str_contains($sql, 'WHEN PG.price_calculation = "biggest" THEN MAX(OP.price)')
                    && str_contains($sql, 'WHEN PG.price_calculation = "average" THEN AVG(OP.price)')
                    && str_contains($sql, 'WHEN PG.price_calculation = "free" THEN 0')
                    && str_contains($sql, 'ELSE SUM(OP.price)')
                    && str_contains($sql, 'WHERE OPO.order_product_id IS NULL');
            }))
            ->willReturn($statement);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getConnection')
            ->willReturn($connection);

        $this->buildService('/orders', $entityManager)->calculateGroupProductPrice($order);

        self::assertSame(72320, $boundParameters[':order_id'] ?? null);
        self::assertSame(72320, $boundParameters[':root_order_id'] ?? null);
        self::assertSame('Brinde fidelidade', $boundParameters[':loyalty_gift_comment'] ?? null);
    }

    public function testSecurityFilterRestrictsOrdersQueueToSale(): void
    {
        $service = $this->buildService('/orders-queue');
        $queryBuilder = $this->createMock(QueryBuilder::class);

        $whereClauses = [];
        $parameters = [];

        $queryBuilder
            ->expects(self::exactly(2))
            ->method('andWhere')
            ->willReturnCallback(function (string $expression) use (&$whereClauses, $queryBuilder) {
                $whereClauses[] = $expression;
                return $queryBuilder;
            });

        $queryBuilder
            ->expects(self::exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function (string $name, mixed $value) use (&$parameters, $queryBuilder) {
                $parameters[$name] = $value;
                return $queryBuilder;
            });

        $service->securityFilter($queryBuilder, null, null, 'orders');

        self::assertContains('orders.client IN(:companies) OR orders.provider IN(:companies)', $whereClauses);
        self::assertContains('orders.orderType = :displayOrderType', $whereClauses);
        self::assertSame([101, 202], $parameters['companies']);
        self::assertSame(OrderService::ORDER_TYPE_SALE, $parameters['displayOrderType']);
    }

    public function testSecurityFilterKeepsRegularOrdersCollectionFlexible(): void
    {
        $service = $this->buildService('/orders');
        $queryBuilder = $this->createMock(QueryBuilder::class);

        $whereClauses = [];
        $parameters = [];

        $queryBuilder
            ->expects(self::once())
            ->method('andWhere')
            ->willReturnCallback(function (string $expression) use (&$whereClauses, $queryBuilder) {
                $whereClauses[] = $expression;
                return $queryBuilder;
            });

        $queryBuilder
            ->expects(self::once())
            ->method('setParameter')
            ->willReturnCallback(function (string $name, mixed $value) use (&$parameters, $queryBuilder) {
                $parameters[$name] = $value;
                return $queryBuilder;
            });

        $service->securityFilter($queryBuilder, null, null, 'orders');

        self::assertContains('orders.client IN(:companies) OR orders.provider IN(:companies)', $whereClauses);
        self::assertArrayNotHasKey('displayOrderType', $parameters);
    }

    public function testSecurityFilterAppliesProviderFilterOnRegularOrdersCollection(): void
    {
        $service = $this->buildService(
            '/orders',
            null,
            null,
            null,
            null,
            [101, 202],
            [],
            null,
            ['provider' => '/people/77'],
        );
        $queryBuilder = $this->createMock(QueryBuilder::class);

        $whereClauses = [];
        $parameters = [];

        $queryBuilder
            ->expects(self::exactly(2))
            ->method('andWhere')
            ->willReturnCallback(function (string $expression) use (&$whereClauses, $queryBuilder) {
                $whereClauses[] = $expression;
                return $queryBuilder;
            });

        $queryBuilder
            ->expects(self::exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function (string $name, mixed $value) use (&$parameters, $queryBuilder) {
                $parameters[$name] = $value;
                return $queryBuilder;
            });

        $service->securityFilter($queryBuilder, null, null, 'orders');

        self::assertContains('orders.client IN(:companies) OR orders.provider IN(:companies)', $whereClauses);
        self::assertContains('orders.provider IN(:provider)', $whereClauses);
        self::assertSame([101, 202], $parameters['companies']);
        self::assertSame('77', $parameters['provider']);
    }

    public function testPreferredProductGroupProductLinkUsesHiddenMappingFirst(): void
    {
        $service = $this->buildService('/orders');

        $visibleGroup = $this->createProductGroup(321, 'Molhos extra à parte');
        $hiddenGroup = $this->createProductGroup(100, 'Molhos extra à parte - 60ml');

        $visibleLink = $this->createProductGroupProductLink(1850, $visibleGroup, true);
        $hiddenLink = $this->createProductGroupProductLink(380, $hiddenGroup, false);

        $resolved = $this->invokePrivateMethod($service, 'pickPreferredProductGroupProductLink', [
            [$visibleLink, $hiddenLink],
            $visibleGroup,
        ]);

        self::assertSame($hiddenLink, $resolved);
        self::assertFalse($resolved->getShowInParentQueue());
        self::assertSame(100, $resolved->getProductGroup()->getId());
    }

    public function testPostPersistQueuesManagerPushNotificationForSaleOrder(): void
    {
        $provider = $this->createMock(People::class);
        $provider
            ->method('getId')
            ->willReturn(3);

        $status = $this->createMock(Status::class);
        $status
            ->method('getRealStatus')
            ->willReturn('open');

        $order = new Order();
        $order->setProvider($provider);
        $order->setStatus($status);
        $order->setOrderType(OrderService::ORDER_TYPE_SALE);
        $this->setEntityId(Order::class, $order, 71608);

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $repository
            ->expects(self::exactly(2))
            ->method('findBy')
            ->with(['people' => $provider])
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(2))
            ->method('getRepository')
            ->with(DeviceConfig::class)
            ->willReturn($repository);

        $integrationService = $this->createMock(IntegrationService::class);
        $integrationService
            ->expects(self::once())
            ->method('addManagerPushIntegrations')
            ->with(
                self::callback(static function (string $payload): bool {
                    $decoded = json_decode($payload, true);

                    return is_array($decoded)
                        && ($decoded['event'] ?? null) === 'order.created'
                        && ($decoded['orderId'] ?? null) === '71608'
                        && ($decoded['companyId'] ?? null) === '3';
                }),
                $provider
            )
            ->willReturn(0);

        $service = $this->buildService(
            '/orders',
            $entityManager,
            null,
            null,
            $integrationService
        );

        $service->postPersist($order);
    }

    public function testPostPersistDoesNotQueueManagerPushNotificationForCartOrder(): void
    {
        $provider = $this->createMock(People::class);
        $provider
            ->method('getId')
            ->willReturn(3);

        $status = $this->createMock(Status::class);
        $status
            ->method('getRealStatus')
            ->willReturn('open');

        $order = new Order();
        $order->setProvider($provider);
        $order->setStatus($status);
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $this->setEntityId(Order::class, $order, 71608);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::never())
            ->method('getRepository');

        $integrationService = $this->createMock(IntegrationService::class);
        $integrationService
            ->expects(self::never())
            ->method('addManagerPushIntegrations');

        $service = $this->buildService(
            '/orders',
            $entityManager,
            null,
            null,
            $integrationService
        );

        $service->postPersist($order);
    }

    private function buildService(
        string $path,
        ?EntityManagerInterface $entityManager = null,
        ?StatusService $statusService = null,
        ?OrderProductQueueService $orderProductQueueService = null,
        ?IntegrationService $integrationService = null,
        array $defaultCompanies = [101, 202],
        array $courierCompanies = [],
        ?People $currentPeople = null,
        array $query = [],
        ?SerializerInterface $serializer = null,
    ): OrderService
    {
        $peopleService = $this->createMock(PeopleService::class);
        $peopleService
            ->method('getMyCompanies')
            ->willReturnCallback(
                function (?array $companyTypes = null) use ($defaultCompanies, $courierCompanies) {
                    if ($companyTypes === ['courier']) {
                        return $courierCompanies;
                    }

                    return $defaultCompanies;
                }
            );
        $peopleService
            ->method('getMyPeople')
            ->willReturn($currentPeople);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create($path, 'GET', $query));

        return new OrderService(
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $this->createMock(TokenStorageInterface::class),
            $peopleService,
            $statusService ?? $this->createMock(StatusService::class),
            $orderProductQueueService ?? $this->createMock(OrderProductQueueService::class),
            $this->createMock(WebsocketClient::class),
            $this->createMock(MessageBusInterface::class),
            $serializer ?? $this->createMock(SerializerInterface::class),
            $requestStack,
            $integrationService,
        );
    }

    private function setEntityId(string $className, object $entity, int $id): void
    {
        $property = new \ReflectionProperty($className, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function createProductGroup(int $id, string $name): ProductGroup
    {
        $productGroup = (new ProductGroup())
            ->setProductGroup($name)
            ->setShowInDisplay(false);

        $this->setEntityId(ProductGroup::class, $productGroup, $id);

        return $productGroup;
    }

    private function createProductGroupProductLink(
        int $id,
        ProductGroup $productGroup,
        bool $showInParentQueue,
    ): ProductGroupProduct {
        $groupProduct = (new ProductGroupProduct())
            ->setProductGroup($productGroup)
            ->setShowInParentQueue($showInParentQueue);

        $this->setEntityId(ProductGroupProduct::class, $groupProduct, $id);

        return $groupProduct;
    }

    private function invokePrivateMethod(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($object, ...$arguments);
    }

    private function createStatusMock(string $realStatus): Status
    {
        $status = $this->createMock(Status::class);
        $status
            ->method('getRealStatus')
            ->willReturn($realStatus);

        return $status;
    }

    private function createStatusEntity(int $id, string $realStatus, ?string $status = null): Status
    {
        $entity = new Status();
        $entity->setRealStatus($realStatus);
        $entity->setStatus($status ?? $realStatus);
        $entity->setContext('order');
        $this->setEntityId(Status::class, $entity, $id);

        return $entity;
    }
}
