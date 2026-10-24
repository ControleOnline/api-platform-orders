<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Device;
use ControleOnline\Entity\Inventory;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\DeviceService;
use ControleOnline\Service\OrderProductQueueService;
use ControleOnline\Service\OrderCommercialContextService;
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
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\SerializerInterface;

/** Methods shared by the original class; contracts and visibility are unchanged. */
trait OrderServiceAssertions2
{
    public function testDirectCartUpdateCannotWeakenTrustedPaymentPolicy(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::never())->method('deserialize');
        $service = $this->buildService(
            '/orders/905',
            $this->createMock(EntityManagerInterface::class),
            serializer: $serializer,
        );
        $order = (new Order())
            ->setOrderType(OrderService::ORDER_TYPE_CART)
            ->setPayBeforeProduction(true);

        try {
            $service->updateOrderFromPayload($order, ['payBeforeProduction' => false]);
            self::fail('Client payload should not weaken server-side payment policy.');
        } catch (BadRequestHttpException $exception) {
            self::assertStringContainsString('politica server-side', $exception->getMessage());
        }

        self::assertTrue($order->isPayBeforeProductionRequired());
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

    public function testUpdateOrderFromPayloadRejectsSaleBackToCart(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer
            ->expects(self::never())
            ->method('deserialize');

        $queueService = $this->createMock(OrderProductQueueService::class);
        $queueService
            ->expects(self::never())
            ->method('syncByOrderStatus');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::never())
            ->method('persist');
        $entityManager
            ->expects(self::never())
            ->method('flush');

        $service = $this->buildService('/orders/903', $entityManager, null, $queueService, null, [], [], null, [], $serializer);

        $order = new Order();
        $order->setApp('POS');
        $order->setOrderType(OrderService::ORDER_TYPE_SALE);
        $order->setStatus($this->createStatusEntity(10, 'open'));
        $this->setEntityId(Order::class, $order, 903);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Sale nao pode voltar para cart por PUT.');

        $service->updateOrderFromPayload($order, [
            'orderType' => 'cart',
            'comments' => 'Reclassificado',
        ]);
    }

    public function testUpdateOrderFromPayloadResolvesClientAndPayerWithoutSerializerIriLookup(): void
    {
        $client = new People();
        $this->setEntityId(People::class, $client, 31482);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer
            ->expects(self::never())
            ->method('deserialize')
            ->willReturnCallback(static function (string $json, string $class, string $format, array $context): Order {
                return $context['object_to_populate'];
            });

        $peopleRepository = $this->createMock(EntityRepository::class);
        $peopleRepository
            ->expects(self::exactly(2))
            ->method('find')
            ->with(31482)
            ->willReturn($client);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(2))
            ->method('getRepository')
            ->with(People::class)
            ->willReturn($peopleRepository);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Order::class));
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service = $this->buildService('/orders/904', $entityManager, null, null, null, [], [], null, [], $serializer);

        $order = new Order();
        $order->setApp('POS');
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $order->setStatus($this->createStatusEntity(10, 'open'));
        $this->setEntityId(Order::class, $order, 904);

        $updatedOrder = $service->updateOrderFromPayload($order, [
            'client' => '/people/31482',
            'payer' => '/people/31482',
        ]);

        self::assertSame($order, $updatedOrder);
        self::assertSame($client, $order->getClient());
        self::assertSame($client, $order->getPayer());
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

    public function testSecurityFilterAllowsClientUsersToReadOnlyTheirOwnOrders(): void
    {
        $currentPeople = new People();
        $this->setEntityId(People::class, $currentPeople, 31484);

        $service = $this->buildService(
            '/orders',
            null,
            null,
            null,
            null,
            [],
            [],
            $currentPeople,
            ['provider' => '/people/9'],
            null,
            ['ROLE_CLIENT'],
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

        self::assertContains('(orders.client = :currentPeople OR orders.payer = :currentPeople)', $whereClauses);
        self::assertContains('orders.provider IN(:provider)', $whereClauses);
        self::assertSame($currentPeople, $parameters['currentPeople']);
        self::assertSame('9', $parameters['provider']);
    }
}
