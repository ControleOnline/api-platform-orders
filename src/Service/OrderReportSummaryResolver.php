<?php

namespace ControleOnline\Service;

use ApiPlatform\Metadata\Operation;
use ControleOnline\Entity\Order;
use ControleOnline\Repository\OrderRepository;
use Doctrine\ORM\QueryBuilder;

class OrderReportSummaryResolver implements CollectionSummaryResolverInterface
{
    public function __construct(private OrderRepository $orderRepository)
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

        $insight = strtolower(trim((string) ($context['filters']['insight'] ?? '')));

        if ('' !== $insight) {
            return [
                'operationalInsights' => $this->orderRepository->resolveOperationalInsight(
                    $filteredIdsQueryBuilder,
                    $insight
                ),
            ];
        }

        return [
            'totals' => $this->orderRepository->resolveReportSummaryTotals($filteredIdsQueryBuilder),
            'apps' => $this->orderRepository->resolveReportSummaryApps($filteredIdsQueryBuilder),
            'displays' => $this->orderRepository->resolveReportSummaryDisplays($filteredIdsQueryBuilder),
            'products' => $this->orderRepository->resolveReportTopProducts($filteredIdsQueryBuilder),
            'operationalInsights' => $this->orderRepository->resolveOperationalInsights($filteredIdsQueryBuilder),
        ];
    }
}
