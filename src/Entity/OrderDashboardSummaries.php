<?php
namespace ControleOnline\Entity;

use ControleOnline\Attribute\CollectionSummary;
use ControleOnline\Service\OrderSalesSummaryResolver;

trait OrderDashboardSummaries
{
    #[CollectionSummary(name: 'movement', parameter: 'summary', parameterValue: 'movement',
        groups: ['order:read'], resolver: OrderSalesSummaryResolver::class)]
    private $movementSummary = null;

    #[CollectionSummary(name: 'paidOrders', parameter: 'summary', parameterValue: 'paidOrders',
        groups: ['order:read'], resolver: OrderSalesSummaryResolver::class)]
    private $paidOrdersSummary = null;
}
