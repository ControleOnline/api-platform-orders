<?php
namespace ControleOnline\Orders\Tests\Serializer;

use ControleOnline\Entity\{Order, OrderProduct, Product, ProductGroup};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class OrderCartHierarchyResponseTest extends TestCase
{
    public function testDetailsResponseIncludesLinksWithoutRecursivelyDuplicatingComponents(): void
    {
        $factory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($factory, new MetadataAwareNameConverter($factory))]);
        $order = (new Order())->setPrice(83);
        $product = new Product();
        $group = (new ProductGroup())->setProductGroup('Escolha a bebida')->setShowInDisplay(true);
        $this->id($product, 10); $this->id($group, 20);
        $root = (new OrderProduct())->setOrder($order)->setQuantity(1)->setPrice(73)->setTotal(73);
        $child = (new OrderProduct())->setOrder($order)->setOrderProduct($root)->setParentProduct($product)
            ->setProductGroup($group)->setQuantity(1)->setPrice(10)->setTotal(10);
        $nested = (new OrderProduct())->setOrder($order)->setOrderProduct($child)->setQuantity(1)->setPrice(2)->setTotal(2);
        $this->id($root, 1); $this->id($child, 2); $this->id($nested, 3);
        $root->addOrderProductComponent($child); $child->addOrderProductComponent($nested);
        foreach ([$root, $child, $nested] as $line) $order->addOrderProduct($line);
        $data = $serializer->normalize($order, null, ['groups' => ['order_details:read']]);
        self::assertCount(3, $data['orderProducts']);
        self::assertNull($data['orderProducts'][0]['orderProduct']);
        self::assertSame('/order_products/1', $data['orderProducts'][1]['orderProduct']);
        self::assertSame('/order_products/2', $data['orderProducts'][2]['orderProduct']);
        self::assertSame('/products/10', $data['orderProducts'][1]['parentProduct']);
        self::assertSame('Escolha a bebida', $data['orderProducts'][1]['productGroup']['productGroup']);
        self::assertTrue($data['orderProducts'][1]['productGroup']['showInDisplay']);
        self::assertArrayNotHasKey('parentProducts', $data['orderProducts'][1]['productGroup']);
        self::assertArrayNotHasKey('orderProductComponents', $data['orderProducts'][0]);
        self::assertEquals(83, $data['price']);
    }

    public function testNullOmissionStillMarksACompleteSimpleLine(): void
    {
        $factory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($factory, new MetadataAwareNameConverter($factory))]);
        $data = $serializer->normalize(new OrderProduct(), null,
            ['groups' => ['order_details:read'], 'skip_null_values' => true]);
        self::assertTrue($data['hierarchyComplete']);
        self::assertArrayNotHasKey('orderProduct', $data);
        self::assertArrayNotHasKey('parentProduct', $data);
        self::assertArrayNotHasKey('productGroup', $data);
    }

    public function testKernelJsonLdUsesCompactLinksOnTheActualOrderResponse(): void
    {
        require_once ($_SERVER['CONTROLEONLINE_TEST_APP_ROOT'] ?? dirname(__DIR__, 5)) . '/config/bootstrap.php';
        $kernel = new class('dev', true) extends \App\Kernel {
            public function getProjectDir(): string { return $_SERVER['CONTROLEONLINE_TEST_APP_ROOT'] ?? parent::getProjectDir(); }
        };
        $kernel->boot();
        try {
            $controller = $kernel->getContainer()->get(\ControleOnline\Controller\OrderProductCollectionController::class);
            $hydrator = (new \ReflectionProperty($controller, 'hydratorService'))->getValue($controller);
            $serializer = (new \ReflectionProperty($hydrator, 'serializer'))->getValue($hydrator);
            $order = (new Order())->setPrice(73);
            $root = (new OrderProduct())->setOrder($order)->setQuantity(1)->setTotal(73);
            $child = (new OrderProduct())->setOrder($order)->setOrderProduct($root)->setShowInParentQueue(false)->setQuantity(1)->setTotal(10);
            $this->id($order, 1); $this->id($root, 10); $this->id($child, 11);
            $order->addOrderProduct($root); $order->addOrderProduct($child);
            // Fixtures have no persisted extras. Avoid database reads by using the
            // existing normalizer's context for entities already processed.
            $applied = array_fill_keys(array_map('spl_object_id', [$order, $root, $child]), true);
            foreach ([['order_details:read'], ['order:write', 'order_cart_hierarchy:read']] as $groups) {
                $data = $serializer->normalize($order, 'jsonld', ['groups' => $groups,
                    '_extra_data_applied' => $applied, '_extra_data_cache' => new \WeakMap(),
                    'skip_null_values' => true]);
                self::assertCount(2, $data['orderProducts']);
                self::assertTrue($data['orderProducts'][0]['hierarchyComplete']);
                self::assertTrue($data['orderProducts'][0]['showInParentQueue'] ?? null, json_encode(['groups' => $groups, 'keys' => array_keys($data['orderProducts'][0])]));
                self::assertFalse($data['orderProducts'][1]['showInParentQueue']);
                self::assertSame('/order_products/10', $data['orderProducts'][1]['orderProduct']);
                self::assertArrayNotHasKey('detailsOrderProduct', $data['orderProducts'][1]);
            }
        } finally { $kernel->shutdown(); }
    }

    private function id(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);
    }
}
