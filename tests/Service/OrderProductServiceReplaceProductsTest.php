<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Service\InvoiceService;
use ControleOnline\Service\OrderProductQueueService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\StatusService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class OrderProductServiceReplaceProductsTest extends TestCase
{
    public function testReplaceProductsToOrderKeepsOnlyTheFirstRootItem(): void
    {
        $order = new Order();
        $this->setEntityId(Order::class, $order, 78112);

        $existingRoot = $this->createMock(OrderProduct::class);
        $existingRoot
            ->method('getOrderProductComponents')
            ->willReturn(new ArrayCollection());
        $existingRoot
            ->method('getOrderProductQueues')
            ->willReturn(new ArrayCollection());
        $existingRoot
            ->method('getOrderProduct')
            ->willReturn(null);

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(['order' => $order])
            ->willReturn([$existingRoot]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(OrderProduct::class)
            ->willReturn($repository);
        $entityManager
            ->expects(self::once())
            ->method('remove')
            ->with($existingRoot);
        $entityManager
            ->expects(self::once())
            ->method('flush');
        $entityManager
            ->expects(self::exactly(2))
            ->method('refresh')
            ->with($order);

        $service = $this->getMockBuilder(OrderProductService::class)
            ->setConstructorArgs($this->buildConstructorArgs($entityManager))
            ->onlyMethods(['addProductsToOrder'])
            ->getMock();

        $service
            ->expects(self::once())
            ->method('addProductsToOrder')
            ->with(
                $order,
                [
                    [
                        'product' => '/products/55',
                        'quantity' => 1,
                    ],
                ],
            )
            ->willReturn($order);

        $result = $service->replaceProductsToOrder($order, [
            [
                'product' => '/products/55',
                'quantity' => 1,
            ],
            [
                'product' => '/products/77',
                'quantity' => 1,
            ],
        ]);

        self::assertSame($order, $result);
    }

    public function testReplaceProductsToOrderClearsOrderWhenPayloadIsEmpty(): void
    {
        $order = new Order();
        $this->setEntityId(Order::class, $order, 78113);

        $existingRoot = $this->createMock(OrderProduct::class);
        $existingRoot
            ->method('getOrderProductComponents')
            ->willReturn(new ArrayCollection());
        $existingRoot
            ->method('getOrderProductQueues')
            ->willReturn(new ArrayCollection());
        $existingRoot
            ->method('getOrderProduct')
            ->willReturn(null);

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(['order' => $order])
            ->willReturn([$existingRoot]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(OrderProduct::class)
            ->willReturn($repository);
        $entityManager
            ->expects(self::once())
            ->method('remove')
            ->with($existingRoot);
        $entityManager
            ->expects(self::once())
            ->method('flush');
        $entityManager
            ->expects(self::exactly(2))
            ->method('refresh')
            ->with($order);

        $orderService = $this->createMock(OrderService::class);
        $orderService
            ->expects(self::once())
            ->method('calculateOrderPrice')
            ->with($order);

        $service = $this->getMockBuilder(OrderProductService::class)
            ->setConstructorArgs($this->buildConstructorArgs($entityManager, $orderService))
            ->onlyMethods(['addProductsToOrder'])
            ->getMock();
        $service
            ->expects(self::never())
            ->method('addProductsToOrder');

        $result = $service->replaceProductsToOrder($order, []);

        self::assertSame($order, $result);
    }

    /**
     * @return array{
     *     0: EntityManagerInterface,
     *     1: TokenStorageInterface,
     *     2: PeopleService,
     *     3: OrderService,
     *     4: StatusService,
     *     5: RequestStack,
     *     6: OrderProductQueueService,
     *     7: InvoiceService
     * }
     */
    private function buildConstructorArgs(
        EntityManagerInterface $entityManager,
        ?OrderService $orderService = null,
    ): array {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/orders/78112/replace-products', 'PUT'));

        return [
            $entityManager,
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(PeopleService::class),
            $orderService ?? $this->createMock(OrderService::class),
            $this->createMock(StatusService::class),
            $requestStack,
            $this->createMock(OrderProductQueueService::class),
            $this->createMock(InvoiceService::class),
        ];
    }

    private function setEntityId(string $className, object $entity, int $id): void
    {
        $property = new \ReflectionProperty($className, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
