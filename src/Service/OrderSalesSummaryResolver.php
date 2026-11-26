<?php

namespace ControleOnline\Service;

use ApiPlatform\Metadata\Operation;
use ControleOnline\Entity\Order;
use ControleOnline\Repository\OrderRepository;
use Doctrine\ORM\QueryBuilder;

class OrderSalesSummaryResolver implements CollectionSummaryResolverInterface
{
    public function __construct(private OrderRepository $orderRepository, private OrderDashboardSummary $dashboard)
    {
    }

    public function resolve(
        Operation $operation,
        string $resourceClass,
        array $summaryField,
        QueryBuilder $filteredIdsQueryBuilder,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if (Order::class !== $resourceClass) {
            return null;
        }

        if (($summaryField['name'] ?? null) === 'movement') {
            return $this->dashboard->resolveMovementSummary($filteredIdsQueryBuilder);
        }
        if (($summaryField['name'] ?? null) === 'paidOrders') {
            return $this->dashboard->resolvePaidOrdersSummary($filteredIdsQueryBuilder);
        }

        if (($summaryField['name'] ?? null) === 'operational') {
            return $this->orderRepository->resolveOperationalStatusSummary($filteredIdsQueryBuilder);
        }

        return [
            'sales' => $this->orderRepository->resolveSalesSummary(
                $filteredIdsQueryBuilder,
                $context
            ),
        ];
    }
}
