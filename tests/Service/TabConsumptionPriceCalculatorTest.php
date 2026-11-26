<?php

namespace ControleOnline\Tests\Service;

use ControleOnline\Entity\{Order, People};
use ControleOnline\Service\TabConsumptionPriceCalculator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class TabConsumptionPriceCalculatorTest extends TestCase
{
    public function testConsumptionIgnoresDraftsCanceledSalesAndCanceledLinkedTabs(): void
    {
        $root = $this->root();
        $pages = [1 => [
            $this->row(2, 'sale', 10), $this->row(3, 'sale', 3, 'closed'),
            $this->row(4, 'cart', 100), $this->row(5, 'sale', 40, 'canceled'),
            $this->row(6, 'tab', 99), $this->row(7, 'tab', 60, 'cancelled'),
        ], 6 => [$this->row(8, 'sale', 7), $this->row(9, 'cart', 20)]];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchAllAssociative')
            ->willReturnCallback(function ($sql, $params) use ($pages) {
                self::assertStringContainsString('O.provider_id = :provider_id', $sql);
                self::assertStringContainsString('O.app = :app', $sql);
                self::assertSame(7, $params['provider_id']);
                self::assertSame('POS', $params['app']);
                return array_merge(...array_map(static fn ($id) => $pages[$id] ?? [], $params['parent_ids']));
            });
        self::assertSame(20.0, (new TabConsumptionPriceCalculator($connection))->calculate($root));
        self::assertSame(999.0, (float) $root->getPrice(), 'Calculation must not mutate or persist the root.');
    }

    public function testLinkedTabCyclesCannotDuplicateConsumptionOrLoop(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [$this->row(2, 'tab', 999), $this->row(3, 'sale', 10)],
            [$this->row(1, 'tab', 999), $this->row(4, 'sale', 5)]
        );
        self::assertSame(15.0, (new TabConsumptionPriceCalculator($connection))->calculate($this->root()));
    }

    public function testScopeIsPosTabOnly(): void
    {
        $root = $this->root();
        self::assertTrue(TabConsumptionPriceCalculator::supports($root));
        foreach (['cart', 'table', 'stamp', 'sale'] as $type) {
            $root->setOrderType($type);
            self::assertFalse(TabConsumptionPriceCalculator::supports($root));
        }
        $root->setOrderType('tab')->setApp('SHOP');
        self::assertFalse(TabConsumptionPriceCalculator::supports($root));
    }

    private function root(): Order
    {
        $provider = new People();
        (new \ReflectionProperty(People::class, 'id'))->setValue($provider, 7);
        $root = (new Order())->setApp('POS')->setOrderType('tab')->setProvider($provider)->setPrice(999);
        (new \ReflectionProperty(Order::class, 'id'))->setValue($root, 1);
        return $root;
    }

    private function row(int $id, string $type, float $price, string $status = 'open'): array
    {
        return ['id' => $id, 'order_type' => $type, 'price' => $price, 'real_status' => $status];
    }
}
