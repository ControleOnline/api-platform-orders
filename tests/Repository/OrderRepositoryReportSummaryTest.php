<?php

namespace ControleOnline\Tests\Repository;

use ControleOnline\Entity\Order;
use ControleOnline\Repository\OrderRepository;
use ControleOnline\Service\PeopleService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
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
