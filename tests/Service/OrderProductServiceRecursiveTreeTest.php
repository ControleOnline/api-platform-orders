<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\Status;
use ControleOnline\Service\InvoiceService;
use ControleOnline\Service\OrderProductQueueService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\ProductShowcaseCatalogService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AllowMockObjectsWithoutExpectations]
class OrderProductServiceRecursiveTreeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetOrderProductServiceStaticState();
    }

    public function testPersistsARecursiveComponentTree(): void
    {
        $fixture = $this->createRecursiveFixture();
        [$service, $persisted] = $this->buildService($fixture);

        $service->addProductsToOrder(
            $fixture['order'],
            [$this->recursivePayload(rootQuantity: 2, grandchildProductId: 103)],
        );

        self::assertCount(3, $persisted);
        [$root, $child, $grandchild] = $persisted->getArrayCopy();
        self::assertNull($root->getOrderProduct());
        self::assertSame($root, $child->getOrderProduct());
        self::assertSame($child, $grandchild->getOrderProduct());
        self::assertSame(2.0, (float) $root->getQuantity());
        self::assertSame(2.0, (float) $child->getQuantity());
        self::assertSame(4.0, (float) $grandchild->getQuantity());
    }

    public function testEquivalentRecursiveTreeIncrementsEveryLevel(): void
    {
        $fixture = $this->createRecursiveFixture();
        $root = $this->createOrderProduct($fixture['order'], $fixture['products'][55], 1, 10.0);
        $child = $this->createOrderProduct($fixture['order'], $fixture['products'][102], 1, 2.5);
        $grandchild = $this->createOrderProduct($fixture['order'], $fixture['products'][103], 2, 1.25);
        $this->connect($root, $child, $fixture['products'][55], $fixture['groups'][50]);
        $this->connect($child, $grandchild, $fixture['products'][102], $fixture['groups'][60]);
        foreach ([$root, $child, $grandchild] as $orderProduct) {
            $fixture['order']->addOrderProduct($orderProduct);
        }

        [$service, $persisted] = $this->buildService($fixture);
        $service->addProductsToOrder(
            $fixture['order'],
            [$this->recursivePayload(rootQuantity: 1, grandchildProductId: 103)],
        );

        self::assertCount(0, $persisted);
        self::assertSame(2.0, (float) $root->getQuantity());
        self::assertSame(2.0, (float) $child->getQuantity());
        self::assertSame(4.0, (float) $grandchild->getQuantity());
    }

    public function testDifferentGrandchildKeepsTheRootTreesSeparate(): void
    {
        $fixture = $this->createRecursiveFixture();
        $root = $this->createOrderProduct($fixture['order'], $fixture['products'][55], 1, 10.0);
        $child = $this->createOrderProduct($fixture['order'], $fixture['products'][102], 1, 2.5);
        $grandchild = $this->createOrderProduct($fixture['order'], $fixture['products'][103], 2, 1.25);
        $this->connect($root, $child, $fixture['products'][55], $fixture['groups'][50]);
        $this->connect($child, $grandchild, $fixture['products'][102], $fixture['groups'][60]);
        foreach ([$root, $child, $grandchild] as $orderProduct) {
            $fixture['order']->addOrderProduct($orderProduct);
        }

        [$service, $persisted] = $this->buildService($fixture);
        $service->addProductsToOrder(
            $fixture['order'],
            [$this->recursivePayload(rootQuantity: 1, grandchildProductId: 104)],
        );

        self::assertSame(1.0, (float) $root->getQuantity());
        self::assertCount(3, $persisted);
        self::assertSame($fixture['products'][104], $persisted[2]->getProduct());
        self::assertSame($persisted[1], $persisted[2]->getOrderProduct());
    }

    /** @return array<string, mixed> */
    private function createRecursiveFixture(): array
    {
        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $products = [
            55 => $this->createProduct(55, 10.0),
            102 => $this->createProduct(102, 2.5),
            103 => $this->createProduct(103, 1.25),
            104 => $this->createProduct(104, 1.5),
        ];
        $groups = [
            50 => $this->createProductGroup(50),
            60 => $this->createProductGroup(60),
        ];

        return [
            'order' => $order,
            'products' => $products,
            'groups' => $groups,
            'links' => [
                $this->createLink($products[55], $products[102], $groups[50], 2.5),
                $this->createLink($products[102], $products[103], $groups[60], 1.25),
                $this->createLink($products[102], $products[104], $groups[60], 1.5),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function recursivePayload(float $rootQuantity, int $grandchildProductId): array
    {
        return [
            'product' => '/products/55',
            'quantity' => $rootQuantity,
            'sub_products' => [[
                'product' => '/products/102',
                'productGroup' => '/product_groups/50',
                'quantity' => $rootQuantity,
                'sub_products' => [[
                    'product' => '/products/' . $grandchildProductId,
                    'productGroup' => '/product_groups/60',
                    'quantity' => $rootQuantity * 2,
                ]],
            ]],
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function buildService(array $fixture): array
    {
        $productRepository = $this->createMock(EntityRepository::class);
        $productRepository->method('find')->willReturnCallback(
            static fn (int $id): ?Product => $fixture['products'][$id] ?? null,
        );
        $groupRepository = $this->createMock(EntityRepository::class);
        $groupRepository->method('find')->willReturnCallback(
            static fn (int $id): ?ProductGroup => $fixture['groups'][$id] ?? null,
        );
        $linkRepository = $this->createMock(EntityRepository::class);
        $linkRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($fixture): ?ProductGroupProduct {
                foreach ($fixture['links'] as $link) {
                    if (isset($criteria['product']) && $criteria['product'] !== $link->getProduct()) {
                        continue;
                    }
                    if (
                        ($criteria['productChild'] ?? null) === $link->getProductChild()
                        && ($criteria['productGroup'] ?? null) === $link->getProductGroup()
                    ) {
                        return $link;
                    }
                }

                return null;
            },
        );

        $persisted = new \ArrayObject();
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => match ($class) {
                Product::class => $productRepository,
                ProductGroup::class => $groupRepository,
                ProductGroupProduct::class => $linkRepository,
                default => throw new \LogicException('Unexpected repository: ' . $class),
            },
        );
        $manager->method('persist')->willReturnCallback(
            static function (object $entity) use ($persisted): void {
                if ($entity instanceof OrderProduct) {
                    $persisted->append($entity);
                }
            },
        );

        $statusService = $this->createMock(StatusService::class);
        $statusService->method('discoveryStatus')->willReturn(new Status());
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/orders/1/add-products', 'PUT'));

        return [new OrderProductService(
            $manager,
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(PeopleService::class),
            $this->createMock(OrderService::class),
            $statusService,
            $requestStack,
            $this->createMock(OrderProductQueueService::class),
            $this->createMock(InvoiceService::class),
            $this->createMock(ProductShowcaseCatalogService::class),
        ), $persisted];
    }

    private function connect(
        OrderProduct $parent,
        OrderProduct $child,
        Product $parentProduct,
        ProductGroup $group,
    ): void {
        $child->setParentProduct($parentProduct);
        $child->setProductGroup($group);
        $parent->addOrderProductComponent($child);
    }

    private function createProduct(int $id, float $price): Product
    {
        return (new Product())->setId($id)->setPrice($price);
    }

    private function createProductGroup(int $id): ProductGroup
    {
        $group = new ProductGroup();
        $property = new \ReflectionProperty(ProductGroup::class, 'id');
        $property->setValue($group, $id);

        return $group;
    }

    private function createLink(
        Product $parent,
        Product $child,
        ProductGroup $group,
        float $price,
    ): ProductGroupProduct {
        return (new ProductGroupProduct())
            ->setProduct($parent)
            ->setProductChild($child)
            ->setProductGroup($group)
            ->setPrice($price);
    }

    private function createOrderProduct(
        Order $order,
        Product $product,
        float $quantity,
        float $price,
    ): OrderProduct {
        return (new OrderProduct())
            ->setOrder($order)
            ->setProduct($product)
            ->setStatus(new Status())
            ->setQuantity($quantity)
            ->setPrice($price)
            ->setTotal($price * $quantity);
    }

    private function resetOrderProductServiceStaticState(): void
    {
        foreach (['mainProduct' => true, 'calculateBefore' => []] as $name => $value) {
            $property = new \ReflectionProperty(OrderProductService::class, $name);
            $property->setValue(null, $value);
        }
    }
}
