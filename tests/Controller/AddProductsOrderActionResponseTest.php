<?php
namespace ControleOnline\Orders\Tests\Controller;

use ApiPlatform\Serializer\JsonEncoder;
use ControleOnline\Controller\AddProductsOrderAction;
use ControleOnline\Entity\{Order, OrderProduct, People, User, Product, ProductGroup};
use ControleOnline\Service\{HydratorService, OrderProductService, OrderService, PeopleService};
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{Request, RequestStack};
use Symfony\Component\Security\Core\Authentication\Token\{TokenInterface, Storage\TokenStorageInterface};
use Symfony\Component\Serializer\{Serializer, Mapping\Factory\ClassMetadataFactory,
    Mapping\Loader\AttributeLoader, NameConverter\MetadataAwareNameConverter, Normalizer\ObjectNormalizer};

final class AddProductsOrderActionResponseTest extends TestCase
{
    public function testActualControllerReturnsCompactHierarchyWithExistingFieldsAndNoSecondLookup(): void
    {
        $provider = new People(); $this->id($provider, 3);
        $order = (new Order())->setProvider($provider)->setPrice(73)->setApp('POS'); $this->id($order, 70);
        $root = (new OrderProduct())->setOrder($order)->setQuantity(1)->setPrice(73)->setTotal(73); $this->id($root, 10);
        $product = new Product(); $this->id($product, 1343);
        $group = (new ProductGroup())->setProductGroup('Bebidas')->setShowInDisplay(true); $this->id($group, 20);
        $child = (new OrderProduct())->setOrder($order)->setOrderProduct($root)->setParentProduct($product)->setProductGroup($group)->setShowInParentQueue(false)->setQuantity(1)->setPrice(10)->setTotal(10); $this->id($child, 11);
        $order->addOrderProduct($root); $order->addOrderProduct($child);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($order);
        $lookups = 0;
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(function () use ($repository, &$lookups) { $lookups++; return $repository; });
        $factory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($factory, new MetadataAwareNameConverter($factory))], [new JsonEncoder('jsonld')]);
        $hydrator = new HydratorService($manager, $serializer, new RequestStack());
        $baseline = json_decode($serializer->serialize($order, 'jsonld', ['groups' => ['order:write']]), true);
        $orderService = $this->createStub(OrderService::class);
        $orderService->method('findOrderById')->willReturn($order);
        $productService = $this->createMock(OrderProductService::class);
        $productService->expects(self::once())->method('addProductsToOrderFromContent')->with($order, '[{"product":1343}]')->willReturn($order);
        $user = $this->createStub(User::class); $user->method('getPeople')->willReturn($provider);
        $token = $this->createStub(TokenInterface::class); $token->method('getUser')->willReturn($user);
        $security = $this->createStub(TokenStorageInterface::class); $security->method('getToken')->willReturn($token);
        $controller = new AddProductsOrderAction($hydrator, $productService, $orderService, $this->createStub(PeopleService::class), $security);
        $response = $controller(Request::create('/orders/70/add-products', 'PUT', [], [], [], [], '[{"product":1343}]'), 70);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame('/order_products/10', $data['orderProducts'][1]['orderProduct'] ?? null);
        self::assertTrue($data['orderProducts'][0]['hierarchyComplete']);
        self::assertSame('/products/1343', $data['orderProducts'][1]['parentProduct']);
        self::assertFalse($data['orderProducts'][1]['showInParentQueue']);
        self::assertSame('Bebidas', $data['orderProducts'][1]['productGroup']['productGroup']);
        self::assertTrue($data['orderProducts'][1]['productGroup']['showInDisplay']);
        self::assertArrayNotHasKey('parentProducts', $data['orderProducts'][1]['productGroup']);
        $original = $data;
        foreach ($original['orderProducts'] as &$line) foreach (['orderProduct', 'parentProduct', 'productGroup', 'hierarchyComplete', 'showInParentQueue'] as $field) unset($line[$field]);
        self::assertSame($baseline, $original);
        self::assertSame(0, $lookups);
    }
    private function id(object $entity, int $id): void { (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id); }
}
