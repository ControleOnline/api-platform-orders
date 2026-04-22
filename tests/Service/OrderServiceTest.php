<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use ControleOnline\Service\OrderProductQueueService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\StatusService;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

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

    private function buildService(
        string $path,
        ?EntityManagerInterface $entityManager = null,
        ?StatusService $statusService = null,
        ?OrderProductQueueService $orderProductQueueService = null,
    ): OrderService
    {
        $peopleService = $this->createMock(PeopleService::class);
        $peopleService
            ->method('getMyCompanies')
            ->willReturn([101, 202]);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create($path, 'GET'));

        return new OrderService(
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $this->createMock(TokenStorageInterface::class),
            $peopleService,
            $statusService ?? $this->createMock(StatusService::class),
            $orderProductQueueService ?? $this->createMock(OrderProductQueueService::class),
            $this->createMock(WebsocketClient::class),
            $requestStack,
        );
    }
}
