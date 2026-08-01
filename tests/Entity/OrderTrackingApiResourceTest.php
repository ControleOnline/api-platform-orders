<?php

namespace ControleOnline\Orders\Tests\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\OrderProductQueue;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\Queue;
use ControleOnline\Entity\Status;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Attribute\Groups;

class OrderTrackingApiResourceTest extends TestCase
{
    public function testTrackingCollectionUsesItsLeanSerializationContract(): void
    {
        $resource = (new \ReflectionClass(Order::class))
            ->getAttributes(ApiResource::class)[0]
            ->newInstance();

        $operations = array_values(array_filter(
            iterator_to_array($resource->getOperations()->getIterator()),
            static fn (object $operation): bool =>
                $operation instanceof GetCollection
                && '/orders-tracking' === $operation->getUriTemplate(),
        ));

        self::assertCount(1, $operations);
        self::assertSame(
            ['groups' => ['tracking:read'], 'enable_max_depth' => true],
            $operations[0]->getNormalizationContext(),
        );
        self::assertFalse($operations[0]->getForceEager());
    }

    public function testTrackingContractContainsOnlyTheOperationalRelationsItNeeds(): void
    {
        foreach (['id', 'orderDate', 'orderProducts', 'status', 'orderType', 'app', 'externalCode'] as $property) {
            self::assertTrackingGroupOn(Order::class, $property);
        }

        foreach ([
            'id',
            'product',
            'status',
            'orderProduct',
            'productGroup',
            'orderProductQueues',
            'quantity',
            'price',
            'total',
            'comment',
            'showInParentQueue',
        ] as $property) {
            self::assertTrackingGroupOn(OrderProduct::class, $property);
        }

        foreach (['id', 'product', 'type'] as $property) {
            self::assertTrackingGroupOn(Product::class, $property);
        }

        foreach (['id', 'productGroup', 'showInDisplay', 'showInPrint', 'showUnitQuantity', 'customizationType', 'groupOrder'] as $property) {
            self::assertTrackingGroupOn(ProductGroup::class, $property);
        }

        foreach (['id', 'registerTime', 'updateTime', 'status', 'queue'] as $property) {
            self::assertTrackingGroupOn(OrderProductQueue::class, $property);
        }

        foreach (['id', 'queue', 'shortLabel', 'icon'] as $property) {
            self::assertTrackingGroupOn(Queue::class, $property);
        }

        foreach (['id', 'status', 'realStatus', 'color'] as $property) {
            self::assertTrackingGroupOn(Status::class, $property);
        }

        foreach (['client', 'orderFiles'] as $property) {
            self::assertNotContains('tracking:read', self::groupsFor(Order::class, $property));
        }

        foreach (['parentProduct', 'orderProductComponents', 'productShowcaseItem'] as $property) {
            self::assertNotContains('tracking:read', self::groupsFor(OrderProduct::class, $property));
        }

        foreach (['extraData', 'productFiles', 'company'] as $property) {
            self::assertNotContains('tracking:read', self::groupsFor(Product::class, $property));
        }
    }

    private static function assertTrackingGroupOn(string $className, string $property): void
    {
        self::assertContains(
            'tracking:read',
            self::groupsFor($className, $property),
            sprintf('%s::$%s must be present in the Tracking payload.', $className, $property),
        );
    }

    /** @return list<string> */
    private static function groupsFor(string $className, string $property): array
    {
        $attributes = (new \ReflectionProperty($className, $property))
            ->getAttributes(Groups::class);

        return $attributes === [] ? [] : $attributes[0]->newInstance()->getGroups();
    }
}
