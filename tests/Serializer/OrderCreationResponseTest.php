<?php
namespace ControleOnline\Orders\Tests\Serializer;

use ApiPlatform\Metadata\{ApiResource, Post};
use ControleOnline\Entity\{Order, OrderProduct};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class OrderCreationResponseTest extends TestCase
{
    public function testPostAddsActualItemsWithoutChangingExistingReadFields(): void
    {
        $resource = (new \ReflectionClass(Order::class))->getAttributes(ApiResource::class)[0]->newInstance();
        $post = array_values(array_filter(iterator_to_array($resource->getOperations()),
            fn ($operation) => $operation instanceof Post && !$operation->getUriTemplate()))[0];
        $context = $post->getNormalizationContext();
        self::assertSame(['order:read', 'order_creation:read'], $context['groups']);
        $serializer = new Serializer([new ObjectNormalizer(new ClassMetadataFactory(new AttributeLoader()))]);
        $order = (new Order())->setApp('POS')->setOrderType('cart')->setPrice(0);
        $before = $serializer->normalize($order, null, ['groups' => ['order:read']]);
        $after = $serializer->normalize($order, null, $context);
        self::assertSame([], $after['orderProducts']);
        unset($after['orderProducts']);
        self::assertSame($before, $after);
        self::assertArrayNotHasKey('orderProducts', $before);
        $line = (new OrderProduct())->setOrder($order)->setQuantity(2)->setPrice(5)->setTotal(10);
        (new \ReflectionProperty(OrderProduct::class, 'id'))->setValue($line, 99);
        $order->addOrderProduct($line);
        $withItems = $serializer->normalize($order, null, $context);
        self::assertCount(1, $withItems['orderProducts']);
        self::assertSame(99, $withItems['orderProducts'][0]['id']);
        self::assertEquals(2, $withItems['orderProducts'][0]['quantity']);
        self::assertEquals(10, $withItems['orderProducts'][0]['total']);
    }
}
