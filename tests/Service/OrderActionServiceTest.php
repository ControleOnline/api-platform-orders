<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\Status;
use ControleOnline\Service\OrderActionService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class OrderActionServiceTest extends TestCase
{
    public function testShopConfirmRequiresDeliveryAddress(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $statusService = $this->createMock(StatusService::class);
        $orderService = $this->createMock(OrderService::class);

        $entityManager
            ->expects(self::never())
            ->method('flush');

        $orderService
            ->expects(self::never())
            ->method('convertDraftOrderToSale');

        $order = new Order();
        $order->setApp('SHOP');
        $order->setOrderType(Order::ORDER_TYPE_CART);

        $service = new OrderActionService(
            $entityManager,
            $statusService,
            $orderService,
        );

        $result = $service->confirm($order);

        self::assertSame(10002, $result['errno']);
        self::assertSame(
            'Pedido do Shop sem endereco de entrega valido.',
            $result['errmsg'],
        );
        self::assertSame(Order::ORDER_TYPE_CART, $order->getOrderType());
    }

    public function testConfirmPromotesPosCartToSaleAndDispatchesCreationEvent(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $statusService = $this->createMock(StatusService::class);
        $orderService = $this->createMock(OrderService::class);
        $preparingStatus = $this->createMock(Status::class);

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('open', 'preparing', 'order')
            ->willReturn($preparingStatus);

        $order = new Order();
        $order->setApp('POS');
        $order->setOrderType(Order::ORDER_TYPE_CART);

        $orderService
            ->expects(self::once())
            ->method('convertDraftOrderToSale')
            ->with($order)
            ->willReturnCallback(static function (Order $order): bool {
                $order->setOrderType(Order::ORDER_TYPE_SALE);

                return true;
            });

        $orderService
            ->expects(self::once())
            ->method('dispatchOrderCreated')
            ->with($order);

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($preparingStatus): bool {
                return $entity instanceof Order
                    && $entity->getStatus() === $preparingStatus
                    && $entity->getOrderType() === Order::ORDER_TYPE_SALE;
            }));

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service = new OrderActionService(
            $entityManager,
            $statusService,
            $orderService,
        );

        $result = $service->confirm($order);

        self::assertSame(0, $result['errno']);
        self::assertSame('ok', $result['errmsg']);
        self::assertSame(Order::ORDER_TYPE_SALE, $order->getOrderType());
        self::assertSame($preparingStatus, $order->getStatus());
    }

    public function testDeliveredPromotesPosCartToSaleBeforeClosing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $statusService = $this->createMock(StatusService::class);
        $orderService = $this->createMock(OrderService::class);
        $closedStatus = $this->createMock(Status::class);

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('closed', 'closed', 'order')
            ->willReturn($closedStatus);

        $order = new Order();
        $order->setApp('POS');
        $order->setOrderType(Order::ORDER_TYPE_CART);

        $orderService
            ->expects(self::once())
            ->method('convertDraftOrderToSale')
            ->with($order)
            ->willReturnCallback(static function (Order $order): bool {
                $order->setOrderType(Order::ORDER_TYPE_SALE);

                return true;
            });

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($closedStatus): bool {
                return $entity instanceof Order
                    && $entity->getStatus() === $closedStatus
                    && $entity->getOrderType() === Order::ORDER_TYPE_SALE;
            }));

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service = new OrderActionService(
            $entityManager,
            $statusService,
            $orderService,
        );

        $result = $service->delivered($order);

        self::assertSame(0, $result['errno']);
        self::assertSame('ok', $result['errmsg']);
        self::assertSame(Order::ORDER_TYPE_SALE, $order->getOrderType());
        self::assertSame($closedStatus, $order->getStatus());
    }
}
