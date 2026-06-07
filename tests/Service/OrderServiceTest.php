<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

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

    public function testPostPersistQueuesManagerPushNotificationForOpenOrder(): void
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
        $this->setEntityId(Order::class, $order, 71608);

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(['people' => $provider])
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
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
}
