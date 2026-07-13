<?php

namespace ControleOnline\Orders\Tests\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use ControleOnline\Entity\OrderProduct;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Attribute\Groups;

class OrderProductApiResourceTest extends TestCase
{
    public function testDefaultPutUsesLeanMutationResponseWithoutEagerLoading(): void
    {
        $resource = (new \ReflectionClass(OrderProduct::class))
            ->getAttributes(ApiResource::class)[0]
            ->newInstance();

        $putOperations = array_values(array_filter(
            iterator_to_array($resource->getOperations()->getIterator()),
            static fn (object $operation): bool =>
                $operation instanceof Put && null === $operation->getUriTemplate(),
        ));

        self::assertCount(1, $putOperations);
        self::assertSame(
            ['groups' => ['order_product_mutation:read']],
            $putOperations[0]->getNormalizationContext(),
        );
        self::assertFalse($putOperations[0]->getForceEager());
    }

    public function testMutationResponseContainsOnlyScalarOrderProductFields(): void
    {
        foreach (['id', 'quantity', 'price', 'total'] as $propertyName) {
            self::assertContains(
                'order_product_mutation:read',
                $this->groupsFor($propertyName),
                sprintf('The mutation response must expose %s.', $propertyName),
            );
        }

        foreach ([
            'order',
            'product',
            'status',
            'inInventory',
            'outInventory',
            'parentProduct',
            'orderProduct',
            'productGroup',
            'orderProductComponents',
            'orderProductQueues',
        ] as $propertyName) {
            self::assertNotContains(
                'order_product_mutation:read',
                $this->groupsFor($propertyName),
                sprintf('The mutation response must not eager-load %s.', $propertyName),
            );
        }
    }

    /** @return list<string> */
    private function groupsFor(string $propertyName): array
    {
        $attributes = (new \ReflectionProperty(OrderProduct::class, $propertyName))
            ->getAttributes(Groups::class);

        return $attributes === [] ? [] : $attributes[0]->newInstance()->getGroups();
    }
}
