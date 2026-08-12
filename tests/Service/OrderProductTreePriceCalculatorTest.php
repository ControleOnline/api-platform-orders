<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Service\OrderProductTreePriceCalculator;
use PHPUnit\Framework\TestCase;

class OrderProductTreePriceCalculatorTest extends TestCase
{
    public function testRecalculatesNestedComponentsFromLeavesUpwards(): void
    {
        $root = $this->node(2, 73);
        $fries = $this->node(2, 999);
        $seasoning = $this->node(2, 3);
        $sauce = $this->node(4, 2);
        $sumGroup = (new ProductGroup())->setPriceCalculation('sum');

        $root->addOrderProductComponent($fries);
        $fries->addOrderProductComponent($seasoning->setProductGroup($sumGroup));
        $fries->addOrderProductComponent($sauce->setProductGroup($sumGroup));

        (new OrderProductTreePriceCalculator())->recalculateComponents(
            $root,
            static fn (OrderProduct $node): float => match ($node) {
                $fries => 0.0,
                $seasoning => 3.0,
                $sauce => 2.0,
            },
        );

        self::assertSame(5.0, (float) $fries->getPrice());
        self::assertSame(10.0, (float) $fries->getTotal());
        self::assertSame(73.0, (float) $root->getPrice());
    }

    public function testHonorsBiggestAverageAndFreeGroupRules(): void
    {
        $root = $this->node(1, 10);
        $component = $this->node(1, 0);
        $root->addOrderProductComponent($component);

        foreach ([['biggest', 2], ['biggest', 5], ['average', 4], ['average', 8], ['free', 20]] as [$rule, $price]) {
            $group = $this->group($component, $rule);
            $component->addOrderProductComponent(
                $this->node(1, $price)->setProductGroup($group),
            );
        }

        (new OrderProductTreePriceCalculator())->recalculateComponents(
            $root,
            static fn (OrderProduct $node): float => (float) $node->getPrice(),
        );

        self::assertSame(11.0, (float) $component->getPrice());
    }

    private function node(float $quantity, float $price): OrderProduct
    {
        return (new OrderProduct())
            ->setQuantity($quantity)
            ->setPrice($price)
            ->setTotal($quantity * $price);
    }

    private function group(OrderProduct $component, string $rule): ProductGroup
    {
        static $groups = [];
        $key = spl_object_id($component) . ':' . $rule;

        return $groups[$key] ??= (new ProductGroup())->setPriceCalculation($rule);
    }
}
