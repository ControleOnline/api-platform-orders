<?php

namespace ControleOnline\Orders\Tests\Entity;

use ControleOnline\Entity\Order;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Attribute\Groups;

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

    public function testTemporalPaymentPolicyIsReadOnlyInPublicOrderContract(): void
    {
        $property = new \ReflectionProperty(Order::class, 'payBeforeProduction');
        $attributes = $property->getAttributes(Groups::class);

        self::assertCount(1, $attributes);
        $groups = $attributes[0]->getArguments()[0] ?? [];
        self::assertContains('order:read', $groups);
        self::assertNotContains('order:write', $groups);
    }

    private function setEntityId(string $className, object $entity, int $id): void
    {
        $property = new \ReflectionProperty($className, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
