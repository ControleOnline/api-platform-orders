<?php

namespace ControleOnline\Orders\Tests\Entity;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

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

    public function testOrderDetailsMarksItsEmbeddedProductTreeAsComplete(): void
    {
        $order = new Order();
        $this->setEntityId(Order::class, $order, 72884);
        $parent = (new OrderProduct())->setId(107200);
        $child = (new OrderProduct())
            ->setId(107201)
            ->setOrderProduct($parent);
        $order
            ->addOrderProduct($parent)
            ->addOrderProduct($child);
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([
            new ObjectNormalizer(
                $metadataFactory,
                new MetadataAwareNameConverter($metadataFactory),
            ),
        ]);

        $payload = $serializer->normalize(
            $order,
            null,
            ['groups' => ['order_details:read']],
        );
        $serializedChild = array_values(array_filter(
            $payload['orderProducts'],
            static fn (array $item): bool => 107201 === $item['id'],
        ))[0];

        self::assertTrue($payload['orderProductsTreeComplete']);
        self::assertCount(2, $payload['orderProducts']);
        self::assertSame(
            [
                '@id' => '/order_products/107200',
                'id' => 107200,
            ],
            $serializedChild['orderProduct'],
        );
    }

    private function setEntityId(string $className, object $entity, int $id): void
    {
        $property = new \ReflectionProperty($className, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
