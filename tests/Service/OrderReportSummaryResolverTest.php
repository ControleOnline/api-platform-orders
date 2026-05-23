<?php

namespace ControleOnline\Tests\Service;

use ApiPlatform\Metadata\GetCollection;
use ControleOnline\Entity\Order;
use ControleOnline\Repository\OrderRepository;
use ControleOnline\Service\OrderReportSummaryResolver;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class OrderReportSummaryResolverTest extends TestCase
{
    public function testResolvesOnlyTheRequestedOperationalInsight(): void
    {
        $filteredIdsQueryBuilder = $this->createMock(QueryBuilder::class);
        $repository = $this->createMock(OrderRepository::class);
        $repository->expects(self::once())
            ->method('resolveOperationalInsight')
            ->with($filteredIdsQueryBuilder, 'totals')
            ->willReturn([
                'totals' => [
                    'orders' => 4,
                    'units' => 8,
                ],
            ]);

        $repository->expects(self::never())
            ->method('resolveOperationalInsights');

        $resolver = new OrderReportSummaryResolver($repository);

        self::assertSame([
            'operationalInsights' => [
                'totals' => [
                    'orders' => 4,
                    'units' => 8,
                ],
            ],
        ], $resolver->resolve(
            new GetCollection(class: Order::class),
            Order::class,
            [],
            $filteredIdsQueryBuilder,
            [],
            ['filters' => ['insight' => 'totals']]
        ));
    }

    public function testResolvesTheFullOperationalSummaryWhenNoInsightIsRequested(): void
    {
        $filteredIdsQueryBuilder = $this->createMock(QueryBuilder::class);
        $repository = $this->createMock(OrderRepository::class);
        $repository->expects(self::once())
            ->method('resolveReportSummaryTotals')
            ->with($filteredIdsQueryBuilder)
            ->willReturn(['orders' => 2, 'units' => 6]);
        $repository->expects(self::once())
            ->method('resolveReportSummaryApps')
            ->with($filteredIdsQueryBuilder)
            ->willReturn([]);
        $repository->expects(self::once())
            ->method('resolveReportSummaryDisplays')
            ->with($filteredIdsQueryBuilder)
            ->willReturn([]);
        $repository->expects(self::once())
            ->method('resolveReportTopProducts')
            ->with($filteredIdsQueryBuilder)
            ->willReturn([]);
        $repository->expects(self::once())
            ->method('resolveOperationalInsights')
            ->with($filteredIdsQueryBuilder)
            ->willReturn(['totals' => ['orders' => 2, 'units' => 6]]);

        $resolver = new OrderReportSummaryResolver($repository);

        self::assertSame([
            'totals' => ['orders' => 2, 'units' => 6],
            'apps' => [],
            'displays' => [],
            'products' => [],
            'operationalInsights' => ['totals' => ['orders' => 2, 'units' => 6]],
        ], $resolver->resolve(
            new GetCollection(class: Order::class),
            Order::class,
            [],
            $filteredIdsQueryBuilder,
            [],
            []
        ));
    }
}
