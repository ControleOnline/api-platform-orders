<?php

namespace ControleOnline\Orders\Tests\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use ControleOnline\Entity\OrderProduct;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class OrderProductApiResourceTest extends TestCase
{
    public function testItemReadLoadsTheRecursiveTreeWithoutBuildingAnUnboundedJoinGraph(): void
    {
        $resource = (new \ReflectionClass(OrderProduct::class))
            ->getAttributes(ApiResource::class)[0]
            ->newInstance();

        $getOperations = array_values(array_filter(
            iterator_to_array($resource->getOperations()->getIterator()),
            static fn (object $operation): bool =>
                $operation instanceof Get && null === $operation->getUriTemplate(),
        ));

        self::assertCount(1, $getOperations);
        self::assertSame(
            [
                'groups' => ['order_product:read'],
                'enable_max_depth' => true,
            ],
            $getOperations[0]->getNormalizationContext(),
        );
        self::assertFalse($getOperations[0]->getForceEager());
    }

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

    public function testOrderDetailsPayloadContainsExactTreeRelationships(): void
    {
        self::assertContains('order_details:read', $this->groupsFor('productGroup'));

        $parentSummary = new \ReflectionMethod(
            OrderProduct::class,
            'getOrderProductSummary',
        );
        $groups = $parentSummary->getAttributes(Groups::class)[0]
            ->newInstance()
            ->getGroups();
        $serializedName = $parentSummary->getAttributes(SerializedName::class)[0]
            ->newInstance()
            ->getSerializedName();

        self::assertContains('order_details:read', $groups);
        self::assertSame('orderProduct', $serializedName);
    }

    public function testOrderProductSummaryIdentifiesTheExactParentInstance(): void
    {
        $parent = (new OrderProduct())->setId(107200);
        $child = (new OrderProduct())
            ->setId(107201)
            ->setOrderProduct($parent);

        self::assertSame(
            [
                '@id' => '/order_products/107200',
                'id' => 107200,
            ],
            $child->getOrderProductSummary(),
        );
        self::assertNull($parent->getOrderProductSummary());
    }

    public function testOrderDetailsActuallySerializesTheExactParentInstance(): void
    {
        $parent = (new OrderProduct())->setId(107200);
        $child = (new OrderProduct())
            ->setId(107201)
            ->setOrderProduct($parent);
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer(
            [
                new ObjectNormalizer(
                    $metadataFactory,
                    new MetadataAwareNameConverter($metadataFactory),
                ),
            ],
        );

        $payload = $serializer->normalize(
            $child,
            null,
            ['groups' => ['order_details:read']],
        );

        self::assertSame(
            [
                '@id' => '/order_products/107200',
                'id' => 107200,
            ],
            $payload['orderProduct'],
        );
    }

    public function testItemReadActuallySerializesTheCompleteForwardTree(): void
    {
        $root = (new OrderProduct())->setId(107200);
        $child = (new OrderProduct())->setId(107201);
        $grandchild = (new OrderProduct())->setId(107202);
        $child->addOrderProductComponent($grandchild);
        $root->addOrderProductComponent($child);

        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer(
            [
                new ObjectNormalizer(
                    $metadataFactory,
                    new MetadataAwareNameConverter($metadataFactory),
                ),
            ],
        );

        $payload = $serializer->normalize(
            $root,
            null,
            [
                'groups' => ['order_product:read'],
                'enable_max_depth' => true,
            ],
        );

        self::assertSame(107200, $payload['id']);
        self::assertSame(107201, $payload['orderProductComponents'][0]['id']);
        self::assertSame(
            107202,
            $payload['orderProductComponents'][0]['orderProductComponents'][0]['id'],
        );
    }

    /** @return list<string> */
    private function groupsFor(string $propertyName): array
    {
        $attributes = (new \ReflectionProperty(OrderProduct::class, $propertyName))
            ->getAttributes(Groups::class);

        return $attributes === [] ? [] : $attributes[0]->newInstance()->getGroups();
    }
}
