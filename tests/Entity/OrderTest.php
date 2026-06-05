<?php

namespace ControleOnline\Orders\Tests\Entity;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use PHPUnit\Framework\TestCase;

class OrderTest extends TestCase
{
    public function testRootOrderProductsExposeOnlyItemsWithoutParent(): void
    {
        $order = (new \ReflectionClass(Order::class))->newInstanceWithoutConstructor();
        $root = (new \ReflectionClass(OrderProduct::class))->newInstanceWithoutConstructor();
        $child = (new \ReflectionClass(OrderProduct::class))->newInstanceWithoutConstructor();
        $standalone = (new \ReflectionClass(OrderProduct::class))->newInstanceWithoutConstructor();

        $child->setOrderProduct($root);
        $this->setObjectProperty($order, 'orderProducts', new OrderProductsCollection([
            $root,
            $child,
            $standalone,
        ]));

        self::assertCount(3, $order->getOrderProducts());
        self::assertSame([$root, $standalone], $order->getRootOrderProducts());
    }

    private function setObjectProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}

final class OrderProductsCollection implements \Countable
{
    public function __construct(private array $items)
    {
    }

    public function filter(callable $callback): self
    {
        return new self(array_values(array_filter($this->items, $callback)));
    }

    public function getValues(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
