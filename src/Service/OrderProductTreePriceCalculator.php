<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\ProductGroup;

final class OrderProductTreePriceCalculator
{
    /**
     * Recalculates component prices from the leaves upwards.
     *
     * The root is deliberately left to OrderService's existing SQL rule, which
     * also handles products that have optional groups but no current selection.
     *
     * @param callable(OrderProduct): float $basePriceResolver
     */
    public function recalculateComponents(
        OrderProduct $root,
        callable $basePriceResolver,
    ): void {
        foreach ($root->getOrderProductComponents() as $component) {
            if (!$component instanceof OrderProduct) {
                continue;
            }

            $this->recalculateNode($component, $basePriceResolver);
        }
    }

    /** @param callable(OrderProduct): float $basePriceResolver */
    private function recalculateNode(
        OrderProduct $node,
        callable $basePriceResolver,
    ): float {
        $groupedPrices = [];

        foreach ($node->getOrderProductComponents() as $component) {
            if (!$component instanceof OrderProduct) {
                continue;
            }

            $price = $this->recalculateNode($component, $basePriceResolver);
            $group = $component->getProductGroup();
            $key = $group instanceof ProductGroup
                ? ($group->getId() ?: spl_object_id($group))
                : 'ungrouped';
            $groupedPrices[$key]['calculation'] = $group instanceof ProductGroup
                ? $group->getPriceCalculation()
                : 'sum';
            $groupedPrices[$key]['prices'][] = $price;
        }

        $price = (float) $basePriceResolver($node);
        foreach ($groupedPrices as $groupedPrice) {
            $price += $this->calculateGroupPrice(
                $groupedPrice['prices'],
                $groupedPrice['calculation'],
            );
        }

        $node->setPrice($price);
        $node->setTotal($price * (float) $node->getQuantity());

        return $price;
    }

    /** @param list<float> $prices */
    private function calculateGroupPrice(array $prices, string $calculation): float
    {
        if ($prices === [] || $calculation === 'free') {
            return 0.0;
        }

        return match ($calculation) {
            'biggest' => max($prices),
            'average' => array_sum($prices) / count($prices),
            default => array_sum($prices),
        };
    }
}
