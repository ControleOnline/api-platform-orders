<?php

namespace ControleOnline\Tests\Repository;

use ControleOnline\Entity\Order;
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
class OrderRepositoryReportSummaryTest extends TestCase
{
    public function testBuildsOperationalSummaryForOrdersCollection(): void
    {
        $filteredIdsQueryBuilder = $this->createMock(QueryBuilder::class);
        $filteredIdsQueryBuilder->method('getDQL')->willReturn('SELECT summary_filter.id FROM orders summary_filter');
        $filteredIdsQueryBuilder->method('getParameters')->willReturn(new ArrayCollection());

        $totalsQueryBuilder = $this->mockQueryBuilder([
            [
                'totalOrders' => '2',
                'totalUnits' => '6',
            ],
        ]);

        $appsQueryBuilder = $this->mockQueryBuilder([
            [
                'appName' => 'iFood',
                'totalOrders' => '1',
                'totalUnits' => '4',
            ],
            [
                'appName' => 'POS',
                'totalOrders' => '1',
                'totalUnits' => '2',
            ],
        ]);

        $displaysQueryBuilder = $this->mockQueryBuilder([
            [
                'displayId' => '7',
                'displayName' => 'Gyros Fritadeira',
                'totalOrders' => '1',
                'queueCount' => '2',
                'totalUnits' => '4',
            ],
        ]);

        $productsQueryBuilder = $this->mockQueryBuilder([
            [
                'productId' => '101',
                'productName' => 'Combo Alpha Gyros',
                'totalOrders' => '1',
                'totalUnits' => '4',
            ],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(4))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(
                $totalsQueryBuilder,
                $appsQueryBuilder,
                $displaysQueryBuilder,
                $productsQueryBuilder,
            );
        $entityManager->method('getClassMetadata')->with(Order::class)->willReturn(new ClassMetadata(Order::class));

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagerForClass')->with(Order::class)->willReturn($entityManager);
        $managerRegistry->method('getManager')->willReturn($entityManager);

        $repository = new OrderRepository(
            $this->createMock(PeopleService::class),
            $managerRegistry
        );

        self::assertSame([
            'orders' => 2,
            'units' => 6.0,
        ], $repository->resolveReportSummaryTotals($filteredIdsQueryBuilder));

        self::assertSame([
            [
                'key' => 'iFood',
                'label' => 'iFood',
                'orders' => 1,
                'units' => 4.0,
            ],
            [
                'key' => 'POS',
                'label' => 'POS',
                'orders' => 1,
                'units' => 2.0,
            ],
        ], $repository->resolveReportSummaryApps($filteredIdsQueryBuilder));

        self::assertSame([
            [
                'displayId' => 7,
                'key' => 'Gyros Fritadeira',
                'label' => 'Gyros Fritadeira',
                'orders' => 1,
                'queueCount' => 2,
                'units' => 4.0,
            ],
        ], $repository->resolveReportSummaryDisplays($filteredIdsQueryBuilder));

        self::assertSame([
            [
                'productId' => 101,
                'key' => 'Combo Alpha Gyros',
                'label' => 'Combo Alpha Gyros',
                'orders' => 1,
                'units' => 4.0,
            ],
        ], $repository->resolveReportTopProducts($filteredIdsQueryBuilder));
    }

    public function testBuildsOperationalInsightsForOrdersCollection(): void
    {
        $filteredIdsQueryBuilder = $this->createMock(QueryBuilder::class);
        $filteredIdsQueryBuilder->method('getDQL')->willReturn('SELECT summary_filter.id FROM orders summary_filter');
        $filteredIdsQueryBuilder->method('getParameters')->willReturn(new ArrayCollection());

        $rootRowsQueryBuilder = $this->mockQueryBuilder([
            [
                'orderId' => '1',
                'orderDate' => '2026-05-22 10:00:00',
                'appName' => 'iFood',
                'productId' => '101',
                'productName' => 'Combo Alpha Gyros',
                'quantity' => '4',
            ],
            [
                'orderId' => '2',
                'orderDate' => '2026-05-22 12:00:00',
                'appName' => 'iFood',
                'productId' => '102',
                'productName' => 'Batata Frita Média',
                'quantity' => '2',
            ],
            [
                'orderId' => '3',
                'orderDate' => '2026-05-23 11:00:00',
                'appName' => 'POS',
                'productId' => '101',
                'productName' => 'Combo Alpha Gyros',
                'quantity' => '1',
            ],
            [
                'orderId' => '4',
                'orderDate' => '2026-05-23 12:15:00',
                'appName' => 'POS',
                'productId' => '103',
                'productName' => 'Sprite lata 350 ml',
                'quantity' => '1',
            ],
        ]);

        $displaysQueryBuilder = $this->mockQueryBuilder([
            [
                'displayId' => '7',
                'displayName' => 'Gyros Fritadeira',
                'totalOrders' => '2',
                'queueCount' => '3',
                'totalUnits' => '5',
            ],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->with(Order::class)->willReturn(new ClassMetadata(Order::class));
        $entityManager->expects(self::exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(
                $rootRowsQueryBuilder,
                $displaysQueryBuilder,
            );

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagerForClass')->with(Order::class)->willReturn($entityManager);
        $managerRegistry->method('getManager')->willReturn($entityManager);

        $repository = new OrderRepository(
            $this->createMock(PeopleService::class),
            $managerRegistry
        );

        self::assertSame([
            'totals' => [
                'orders' => 4,
                'units' => 8.0,
            ],
            'apps' => [
                [
                    'key' => 'iFood',
                    'label' => 'iFood',
                    'orders' => 2,
                    'units' => 6.0,
                ],
                [
                    'key' => 'POS',
                    'label' => 'POS',
                    'orders' => 2,
                    'units' => 2.0,
                ],
            ],
            'displays' => [
                [
                    'displayId' => 7,
                    'key' => 'Gyros Fritadeira',
                    'label' => 'Gyros Fritadeira',
                    'orders' => 2,
                    'queueCount' => 3,
                    'units' => 5.0,
                ],
            ],
            'daily' => [
                [
                    'date' => '2026-05-22',
                    'label' => '22/05',
                    'orders' => 2,
                    'units' => 6.0,
                ],
                [
                    'date' => '2026-05-23',
                    'label' => '23/05',
                    'orders' => 2,
                    'units' => 2.0,
                ],
            ],
            'products' => [
                [
                    'productId' => 101,
                    'key' => 'Combo Alpha Gyros',
                    'label' => 'Combo Alpha Gyros',
                    'orders' => 2,
                    'units' => 5.0,
                ],
                [
                    'productId' => 102,
                    'key' => 'Batata Frita Média',
                    'label' => 'Batata Frita Média',
                    'orders' => 1,
                    'units' => 2.0,
                ],
                [
                    'productId' => 103,
                    'key' => 'Sprite lata 350 ml',
                    'label' => 'Sprite lata 350 ml',
                    'orders' => 1,
                    'units' => 1.0,
                ],
            ],
            'abc' => [
                'totalUnits' => 8.0,
                'items' => [
                    [
                        'productId' => 101,
                        'key' => 'Combo Alpha Gyros',
                        'label' => 'Combo Alpha Gyros',
                        'orders' => 2,
                        'units' => 5.0,
                        'share' => 62.5,
                        'cumulativeShare' => 62.5,
                        'bucket' => 'A',
                    ],
                    [
                        'productId' => 102,
                        'key' => 'Batata Frita Média',
                        'label' => 'Batata Frita Média',
                        'orders' => 1,
                        'units' => 2.0,
                        'share' => 25.0,
                        'cumulativeShare' => 87.5,
                        'bucket' => 'B',
                    ],
                    [
                        'productId' => 103,
                        'key' => 'Sprite lata 350 ml',
                        'label' => 'Sprite lata 350 ml',
                        'orders' => 1,
                        'units' => 1.0,
                        'share' => 12.5,
                        'cumulativeShare' => 100.0,
                        'bucket' => 'C',
                    ],
                ],
                'buckets' => [
                    [
                        'bucket' => 'A',
                        'label' => 'A',
                        'items' => 1,
                        'units' => 5.0,
                        'share' => 62.5,
                    ],
                    [
                        'bucket' => 'B',
                        'label' => 'B',
                        'items' => 1,
                        'units' => 2.0,
                        'share' => 25.0,
                    ],
                    [
                        'bucket' => 'C',
                        'label' => 'C',
                        'items' => 1,
                        'units' => 1.0,
                        'share' => 12.5,
                    ],
                ],
            ],
        ], $repository->resolveOperationalInsights($filteredIdsQueryBuilder));
    }

    public function testResolvesSingleOperationalInsightForTotalsOnly(): void
    {
        $filteredIdsQueryBuilder = $this->createMock(QueryBuilder::class);
        $filteredIdsQueryBuilder->method('getDQL')->willReturn('SELECT summary_filter.id FROM orders summary_filter');
        $filteredIdsQueryBuilder->method('getParameters')->willReturn(new ArrayCollection());

        $totalsQueryBuilder = $this->mockQueryBuilder([
            [
                'orderId' => '1',
                'orderDate' => '2026-05-22 10:00:00',
                'appName' => 'iFood',
                'productId' => '101',
                'productName' => 'Combo Alpha Gyros',
                'quantity' => '4',
            ],
            [
                'orderId' => '2',
                'orderDate' => '2026-05-22 12:00:00',
                'appName' => 'POS',
                'productId' => '102',
                'productName' => 'Batata Frita Média',
                'quantity' => '2',
            ],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('createQueryBuilder')
            ->willReturn($totalsQueryBuilder);
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
                'orders' => 2,
                'units' => 6.0,
            ],
        ], $repository->resolveOperationalInsight($filteredIdsQueryBuilder, 'totals'));
    }

    private function mockQueryBuilder(array $rows): QueryBuilder
    {
        $query = $this->createMock(Query::class);
        $query->method('getArrayResult')->willReturn($rows);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        foreach (['from', 'join', 'andWhere', 'select', 'groupBy', 'orderBy', 'addOrderBy', 'setMaxResults', 'setParameter'] as $method) {
            $queryBuilder->method($method)->willReturnSelf();
        }
        $queryBuilder->method('getQuery')->willReturn($query);

        return $queryBuilder;
    }
}
