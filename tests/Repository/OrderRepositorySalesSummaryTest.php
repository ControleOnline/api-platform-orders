<?php

namespace ControleOnline\Tests\Repository;

use ControleOnline\Entity\People;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\Product;
use ControleOnline\Repository\OrderRepository;
use ControleOnline\Service\PeopleService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class OrderRepositorySalesSummaryTest extends TestCase
{
    public function testBuildsSalesSummaryGroupedByDayWeekAndMonth(): void
    {
        $filteredIdsQueryBuilder = $this->createMock(QueryBuilder::class);
        $filteredIdsQueryBuilder->method('getDQL')->willReturn('SELECT summary_filter.id FROM orders summary_filter');
        $filteredIdsQueryBuilder->method('getParameters')->willReturn(new ArrayCollection());

        $summaryQueryBuilder = $this->mockQueryBuilder([
            [
                'orderId' => '1',
                'orderDate' => '2026-06-01 10:00:00',
                'quantity' => '2',
                'total' => '20',
            ],
            [
                'orderId' => '1',
                'orderDate' => '2026-06-01 10:00:00',
                'quantity' => '1',
                'total' => '10',
            ],
            [
                'orderId' => '2',
                'orderDate' => '2026-06-02 12:00:00',
                'quantity' => '3',
                'total' => '30',
            ],
            [
                'orderId' => '3',
                'orderDate' => '2026-06-08 09:00:00',
                'quantity' => '4',
                'total' => '40',
            ],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('createQueryBuilder')
            ->willReturn($summaryQueryBuilder);
        $entityManager->method('getClassMetadata')->with(Order::class)->willReturn(new ClassMetadata(Order::class));

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagerForClass')->with(Order::class)->willReturn($entityManager);
        $managerRegistry->method('getManager')->willReturn($entityManager);

        $repository = new OrderRepository(
            $this->createMock(PeopleService::class),
            $managerRegistry
        );

        self::assertSame([
            'totals' => [
                'orders' => 3,
                'units' => 10.0,
                'revenue' => 100.0,
                'averageTicket' => 100.0 / 3.0,
            ],
            'daily' => [
                [
                    'key' => '2026-06-01',
                    'label' => '01/06',
                    'orders' => 1,
                    'units' => 3.0,
                    'revenue' => 30.0,
                ],
                [
                    'key' => '2026-06-02',
                    'label' => '02/06',
                    'orders' => 1,
                    'units' => 3.0,
                    'revenue' => 30.0,
                ],
                [
                    'key' => '2026-06-08',
                    'label' => '08/06',
                    'orders' => 1,
                    'units' => 4.0,
                    'revenue' => 40.0,
                ],
            ],
            'weekly' => [
                [
                    'key' => '2026-W23',
                    'label' => 'Sem 23 · 01/06 - 07/06',
                    'orders' => 2,
                    'units' => 6.0,
                    'revenue' => 60.0,
                ],
                [
                    'key' => '2026-W24',
                    'label' => 'Sem 24 · 08/06 - 14/06',
                    'orders' => 1,
                    'units' => 4.0,
                    'revenue' => 40.0,
                ],
            ],
            'monthly' => [
                [
                    'key' => '2026-06',
                    'label' => '06/2026',
                    'orders' => 3,
                    'units' => 10.0,
                    'revenue' => 100.0,
                ],
            ],
        ], $repository->resolveSalesSummary($filteredIdsQueryBuilder, []));
    }

    public function testBuildsProductSalesSummaryForTheSelectedProduct(): void
    {
        $company = $this->createMock(People::class);
        $company->method('getId')->willReturn(3);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(1133);
        $product->method('getCompany')->willReturn($company);

        $filteredIdsQueryBuilder = $this->createMock(QueryBuilder::class);
        foreach ([
            'from',
            'select',
            'join',
            'leftJoin',
            'orderBy',
            'addOrderBy',
        ] as $method) {
            $filteredIdsQueryBuilder->method($method)->willReturnSelf();
        }

        $andWhereCalls = [];
        $filteredIdsQueryBuilder->method('andWhere')
            ->willReturnCallback(function (string $expression) use (&$andWhereCalls, $filteredIdsQueryBuilder) {
                $andWhereCalls[] = $expression;
                return $filteredIdsQueryBuilder;
            });

        $setParameters = [];
        $filteredIdsQueryBuilder->method('setParameter')
            ->willReturnCallback(function (string $name, mixed $value) use (&$setParameters, $filteredIdsQueryBuilder) {
                $setParameters[$name] = $value;
                return $filteredIdsQueryBuilder;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('createQueryBuilder')
            ->willReturn($filteredIdsQueryBuilder);
        $entityManager->method('getClassMetadata')->with(Order::class)->willReturn(new ClassMetadata(Order::class));

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagerForClass')->with(Order::class)->willReturn($entityManager);
        $managerRegistry->method('getManager')->willReturn($entityManager);

        $repository = $this->getMockBuilder(OrderRepository::class)
            ->setConstructorArgs([
                $this->createMock(PeopleService::class),
                $managerRegistry,
            ])
            ->onlyMethods(['resolveSalesSummary'])
            ->getMock();

        $repository->expects(self::once())
            ->method('resolveSalesSummary')
            ->with($filteredIdsQueryBuilder, [
                'includeNestedProducts' => true,
                'filters' => [
                    'product' => '/products/1133',
                ],
            ])
            ->willReturn([
                'totals' => [
                    'orders' => 2,
                    'units' => 3,
                    'revenue' => 39,
                    'averageTicket' => 19.5,
                ],
                'daily' => [],
                'weekly' => [],
                'monthly' => [],
            ]);

        $result = $repository->resolveProductSalesSummary(
            $product,
            '2026-05-10 00:00:00',
            '2026-06-08 23:59:59'
        );

        self::assertSame([
            'totals' => [
                'orders' => 2,
                'units' => 3,
                'revenue' => 39,
                'averageTicket' => 19.5,
            ],
            'daily' => [],
            'weekly' => [],
            'monthly' => [],
        ], $result);
        self::assertSame([
            'filtered_order.orderType = :salesOrderType',
            'filtered_status.realStatus = :salesRealStatus',
            'filtered_order_product.orderProduct IS NULL',
            'IDENTITY(filtered_order_product.product) = :salesProductId',
            'IDENTITY(filtered_order.provider) = :salesCompanyId',
            'filtered_order.orderDate >= :salesAfter',
            'filtered_order.orderDate <= :salesBefore',
        ], $andWhereCalls);
        self::assertSame(Order::ORDER_TYPE_SALE, $setParameters['salesOrderType']);
        self::assertSame('closed', $setParameters['salesRealStatus']);
        self::assertSame(1133, $setParameters['salesProductId']);
        self::assertSame(3, $setParameters['salesCompanyId']);
        self::assertSame('2026-05-10 00:00:00', $setParameters['salesAfter']->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-08 23:59:59', $setParameters['salesBefore']->format('Y-m-d H:i:s'));
    }

    public function testProductSalesSummaryIncludesNestedProductRowsWhenRequested(): void
    {
        $filteredIdsQueryBuilder = $this->createMock(QueryBuilder::class);
        $filteredIdsQueryBuilder->method('getDQL')->willReturn('SELECT summary_filter.id FROM orders summary_filter');
        $filteredIdsQueryBuilder->method('getParameters')->willReturn(new ArrayCollection());

        $summaryQuery = $this->createMock(Query::class);
        $summaryQuery->method('getArrayResult')->willReturn([
            [
                'orderId' => '72142',
                'orderDate' => '2026-06-07 18:00:00',
                'quantity' => '1',
                'total' => '0',
            ],
        ]);

        $summaryQueryBuilder = $this->createMock(QueryBuilder::class);
        foreach ([
            'from',
            'join',
            'leftJoin',
            'select',
            'orderBy',
            'addOrderBy',
            'setParameter',
        ] as $method) {
            $summaryQueryBuilder->method($method)->willReturnSelf();
        }
        $andWhereCalls = [];
        $summaryQueryBuilder->method('andWhere')
            ->willReturnCallback(function (string $expression) use (&$andWhereCalls, $summaryQueryBuilder) {
                $andWhereCalls[] = $expression;
                return $summaryQueryBuilder;
            });
        $summaryQueryBuilder->method('getQuery')->willReturn($summaryQuery);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('createQueryBuilder')
            ->willReturn($summaryQueryBuilder);
        $entityManager->method('getClassMetadata')->with(Order::class)->willReturn(new ClassMetadata(Order::class));

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagerForClass')->with(Order::class)->willReturn($entityManager);
        $managerRegistry->method('getManager')->willReturn($entityManager);

        $repository = new OrderRepository(
            $this->createMock(PeopleService::class),
            $managerRegistry
        );

        $result = $repository->resolveSalesSummary($filteredIdsQueryBuilder, [
            'includeNestedProducts' => true,
            'filters' => [
                'product' => '/products/1133',
            ],
        ]);

        self::assertSame([
            'totals' => [
                'orders' => 1,
                'units' => 1.0,
                'revenue' => 0.0,
                'averageTicket' => 0.0,
            ],
            'daily' => [
                [
                    'key' => '2026-06-07',
                    'label' => '07/06',
                    'orders' => 1,
                    'units' => 1.0,
                    'revenue' => 0.0,
                ],
            ],
            'weekly' => [
                [
                    'key' => '2026-W23',
                    'label' => 'Sem 23 · 01/06 - 07/06',
                    'orders' => 1,
                    'units' => 1.0,
                    'revenue' => 0.0,
                ],
            ],
            'monthly' => [
                [
                    'key' => '2026-06',
                    'label' => '06/2026',
                    'orders' => 1,
                    'units' => 1.0,
                    'revenue' => 0.0,
                ],
            ],
        ], $result);
        self::assertContains('IDENTITY(summary_order_product.product) = :salesProductId', $andWhereCalls);
        self::assertNotContains('summary_order_product.orderProduct IS NULL', $andWhereCalls);
    }

    private function mockQueryBuilder(array $rows): QueryBuilder
    {
        $query = $this->createMock(Query::class);
        $query->method('getArrayResult')->willReturn($rows);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        foreach ([
            'from',
            'join',
            'leftJoin',
            'andWhere',
            'select',
            'groupBy',
            'orderBy',
            'addOrderBy',
            'setParameter',
        ] as $method) {
            $queryBuilder->method($method)->willReturnSelf();
        }
        $queryBuilder->method('getQuery')->willReturn($query);

        return $queryBuilder;
    }
}
