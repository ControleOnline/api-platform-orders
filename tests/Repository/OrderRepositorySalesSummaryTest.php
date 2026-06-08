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
