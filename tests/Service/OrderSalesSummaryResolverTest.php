<?php

namespace ControleOnline\Tests\Service;

use ApiPlatform\Metadata\GetCollection;
use ControleOnline\Entity\Order;
use ControleOnline\Repository\OrderRepository;
use ControleOnline\Service\OrderSalesSummaryResolver;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class OrderSalesSummaryResolverTest extends TestCase
{
    public function testResolvesSalesSummaryFromTheRepository(): void
    {
        $filteredIdsQueryBuilder = $this->createMock(QueryBuilder::class);
        $repository = $this->createMock(OrderRepository::class);

        $repository->expects(self::once())
            ->method('resolveSalesSummary')
            ->with(
                $filteredIdsQueryBuilder,
                ['filters' => ['summary' => 'sales']]
            )
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

        $resolver = new OrderSalesSummaryResolver($repository);

        self::assertSame([
            'sales' => [
                'totals' => [
                    'orders' => 2,
                    'units' => 3,
                    'revenue' => 39,
                    'averageTicket' => 19.5,
                ],
                'daily' => [],
                'weekly' => [],
                'monthly' => [],
            ],
        ], $resolver->resolve(
            new GetCollection(class: Order::class),
            Order::class,
            [],
            $filteredIdsQueryBuilder,
            [],
            ['filters' => ['summary' => 'sales']]
        ));
    }
}
