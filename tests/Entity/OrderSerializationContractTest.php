<?php

namespace ControleOnline\Orders\Tests\Entity;

use ControleOnline\Entity\Order;
use PHPUnit\Framework\TestCase;

class OrderSerializationContractTest extends TestCase
{
    public function testMainOrderSummaryReturnsLightweightPayload(): void
    {
        $mainOrder = new Order();
        $mainOrder->setExternalCode('570002');
        $this->setEntityId(Order::class, $mainOrder, 71234);

        $order = new Order();
        $order->setMainOrder($mainOrder);

        self::assertSame(
            [
                'id' => 71234,
                'externalCode' => '570002',
            ],
            $order->getMainOrderSummary(),
        );
    }

    public function testMainOrderSummaryReturnsNullWithoutMainOrder(): void
    {
        $order = new Order();

        self::assertNull($order->getMainOrderSummary());
    }

    private function setEntityId(string $className, object $entity, int $id): void
    {
        $property = new \ReflectionProperty($className, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
