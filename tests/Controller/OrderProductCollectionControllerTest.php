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

        $orderProductService = $this->createOrderProductServiceStub();

        $controller = new OrderProductCollectionController($manager, $hydratorService, $orderProductService);
        $response = $controller->__invoke(
            Request::create('/order_products', 'GET', [
                'order_id' => '72133',
                'itemsPerPage' => '200',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $orderProductService->securityFilterCalls);
        foreach ($orderProductService->securityFilterCalls as $call) {
            self::assertInstanceOf(QueryBuilder::class, $call[0]);
            self::assertSame(OrderProduct::class, $call[1]);
            self::assertSame('collection', $call[2]);
            self::assertSame('orderProduct', $call[3]);
        }
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

        $orderProductService = $this->createOrderProductServiceStub();

        $controller = new OrderProductCollectionController($manager, $hydratorService, $orderProductService);
        $response = $controller->__invoke(
            Request::create('/order_products', 'GET', [
                'order' => '/orders/72133',
                'itemsPerPage' => '50',
                'page' => '2',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $orderProductService->securityFilterCalls);
        self::assertSame($payload, json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['max' => 50, 'first' => 50], $itemsPagination);
        self::assertSame(3, $capturedCollectionArguments[4]);
    }

    public function testResolvesNestedOrderIdQuery(): void
    {
        $orderReference = $this->createStub(Order::class);
        $countFilters = [];
        $itemsFilters = [];

        $countQueryBuilder = $this->createQueryBuilderMock(
            countResult: 0,
            andWhereCalls: $countFilters,
        );
        $itemsQueryBuilder = $this->createQueryBuilderMock(
            result: [],
            andWhereCalls: $itemsFilters,
        );

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($countQueryBuilder, $itemsQueryBuilder);
        $manager
            ->expects(self::exactly(2))
            ->method('getReference')
            ->with(Order::class, 72133)
            ->willReturn($orderReference);

        $hydratorService = $this->createStub(HydratorService::class);
        $hydratorService
            ->method('collectionData')
            ->willReturn(['member' => [], 'totalItems' => 0]);

        $orderProductService = $this->createOrderProductServiceStub();

        $controller = new OrderProductCollectionController($manager, $hydratorService, $orderProductService);
        $response = $controller->__invoke(
            Request::create('/order_products', 'GET', [
                'order.id' => '72133',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $orderProductService->securityFilterCalls);
        self::assertSame(['orderProduct.order = :orderProductOrder'], $countFilters);
        self::assertSame(['orderProduct.order = :orderProductOrder'], $itemsFilters);
    }

    private function createQueryBuilderMock(
        array $result = [],
        int $countResult = 0,
        array &$andWhereCalls = [],
        array &$paginationCalls = [],
    ): QueryBuilder {
        $query = $this->createStub(Query::class);
        $query
            ->method('getResult')
            ->willReturn($result);
        $query
            ->method('getSingleScalarResult')
            ->willReturn((string) $countResult);

        $queryBuilder = $this->createStub(QueryBuilder::class);

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

    private function createOrderProductServiceStub(): OrderProductService
    {
        return new class extends OrderProductService {
            public array $securityFilterCalls = [];

            public function __construct()
            {
            }

            public function securityFilter(QueryBuilder $queryBuilder, $resourceClass = null, $applyTo = null, $rootAlias = null): void
            {
                $this->securityFilterCalls[] = [$queryBuilder, $resourceClass, $applyTo, $rootAlias];
            }

            public function __destruct()
            {
            }
        };
    }
}
