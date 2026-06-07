<?php

namespace ControleOnline\Orders\Tests\Controller;

use ControleOnline\Controller\OrderProductCollectionController;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderProductService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class OrderProductCollectionControllerTest extends TestCase
{
    public function testResolvesOrderIdQueryAndReturnsSecureHydratedCollection(): void
    {
        $items = [new OrderProduct()];
        $orderReference = $this->createStub(Order::class);
        $payload = [
            'member' => [
                ['id' => 72133],
            ],
            'totalItems' => 1,
        ];
        $capturedCollectionArguments = null;
        $countFilters = [];
        $itemsFilters = [];
        $itemsPagination = [];

        $countQueryBuilder = $this->createQueryBuilderMock(
            countResult: 1,
            andWhereCalls: $countFilters,
        );
        $itemsQueryBuilder = $this->createQueryBuilderMock(
            result: $items,
            andWhereCalls: $itemsFilters,
            paginationCalls: $itemsPagination,
        );

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($countQueryBuilder, $itemsQueryBuilder);
        $manager
            ->method('getReference')
            ->with(Order::class, 72133)
            ->willReturn($orderReference);

        $hydratorService = $this->createStub(HydratorService::class);
        $hydratorService
            ->method('collectionData')
            ->willReturnCallback(function (...$arguments) use ($payload, &$capturedCollectionArguments) {
                $capturedCollectionArguments = $arguments;

                return $payload;
            });

        $orderProductService = $this->createMock(OrderProductService::class);
        $orderProductService
            ->expects(self::exactly(2))
            ->method('securityFilter')
            ->with(
                self::isInstanceOf(QueryBuilder::class),
                OrderProduct::class,
                'collection',
                'orderProduct',
            );

        $controller = new OrderProductCollectionController($manager, $hydratorService, $orderProductService);
        $response = $controller->__invoke(
            Request::create('/order_products', 'GET', [
                'order_id' => '72133',
                'itemsPerPage' => '200',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($payload, json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['orderProduct.order = :orderProductOrder'], $countFilters);
        self::assertSame(['orderProduct.order = :orderProductOrder'], $itemsFilters);
        self::assertSame(['max' => 200, 'first' => 0], $itemsPagination);
        self::assertSame(
            [
                $items,
                OrderProduct::class,
                'order_product:read',
                [],
                1,
            ],
            array_slice($capturedCollectionArguments, 0, 5),
        );
    }

    public function testResolvesOrderIriAndPagination(): void
    {
        $items = [new OrderProduct()];
        $orderReference = $this->createStub(Order::class);
        $payload = [
            'member' => [
                ['id' => 72133],
            ],
            'totalItems' => 1,
        ];
        $capturedCollectionArguments = null;
        $itemsPagination = [];

        $countQueryBuilder = $this->createQueryBuilderMock(countResult: 3);
        $itemsQueryBuilder = $this->createQueryBuilderMock(
            result: $items,
            paginationCalls: $itemsPagination,
        );

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($countQueryBuilder, $itemsQueryBuilder);
        $manager
            ->method('getReference')
            ->with(Order::class, 72133)
            ->willReturn($orderReference);

        $hydratorService = $this->createStub(HydratorService::class);
        $hydratorService
            ->method('collectionData')
            ->willReturnCallback(function (...$arguments) use ($payload, &$capturedCollectionArguments) {
                $capturedCollectionArguments = $arguments;

                return $payload;
            });

        $orderProductService = $this->createMock(OrderProductService::class);
        $orderProductService
            ->expects(self::exactly(2))
            ->method('securityFilter');

        $controller = new OrderProductCollectionController($manager, $hydratorService, $orderProductService);
        $response = $controller->__invoke(
            Request::create('/order_products', 'GET', [
                'order' => '/orders/72133',
                'itemsPerPage' => '50',
                'page' => '2',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($payload, json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['max' => 50, 'first' => 50], $itemsPagination);
        self::assertSame(3, $capturedCollectionArguments[4]);
    }

    private function createQueryBuilderMock(
        array $result = [],
        int $countResult = 0,
        array &$andWhereCalls = [],
        array &$paginationCalls = [],
    ): QueryBuilder {
        $query = $this->createMock(Query::class);
        $query
            ->method('getResult')
            ->willReturn($result);
        $query
            ->method('getSingleScalarResult')
            ->willReturn((string) $countResult);

        $queryBuilder = $this
            ->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'select',
                'from',
                'andWhere',
                'setParameter',
                'addOrderBy',
                'setMaxResults',
                'setFirstResult',
                'getQuery',
            ])
            ->getMock();

        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder
            ->method('andWhere')
            ->willReturnCallback(function (string $expression) use (&$andWhereCalls, $queryBuilder): QueryBuilder {
                $andWhereCalls[] = $expression;

                return $queryBuilder;
            });
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder
            ->method('setMaxResults')
            ->willReturnCallback(function (int $maxResults) use (&$paginationCalls, $queryBuilder): QueryBuilder {
                $paginationCalls['max'] = $maxResults;

                return $queryBuilder;
            });
        $queryBuilder
            ->method('setFirstResult')
            ->willReturnCallback(function (int $firstResult) use (&$paginationCalls, $queryBuilder): QueryBuilder {
                $paginationCalls['first'] = $firstResult;

                return $queryBuilder;
            });
        $queryBuilder->method('getQuery')->willReturn($query);

        return $queryBuilder;
    }
}
