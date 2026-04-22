<?php

namespace ControleOnline\Orders\Tests\Service;

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

    private function buildService(string $path): OrderService
    {
        $peopleService = $this->createMock(PeopleService::class);
        $peopleService
            ->method('getMyCompanies')
            ->willReturn([101, 202]);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create($path, 'GET'));

        return new OrderService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(TokenStorageInterface::class),
            $peopleService,
            $this->createMock(StatusService::class),
            $this->createMock(OrderProductQueueService::class),
            $this->createMock(WebsocketClient::class),
            $requestStack,
        );
    }
}
