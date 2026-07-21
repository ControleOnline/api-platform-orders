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

    public function testDeliveredClosesFidelityOrderWithoutChangingOrderType(): void
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
        $order->setOrderType(Order::ORDER_TYPE_FIDELITY);

        $orderService
            ->expects(self::never())
            ->method('convertDraftOrderToSale');

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($closedStatus): bool {
                return $entity instanceof Order
                    && $entity->getStatus() === $closedStatus
                    && $entity->getOrderType() === Order::ORDER_TYPE_FIDELITY;
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
        self::assertSame(Order::ORDER_TYPE_FIDELITY, $order->getOrderType());
        self::assertSame($closedStatus, $order->getStatus());
    }

    public function testConfirmDeliveryOrderUsesDeliveryAcceptedStatusWithoutPromotingCart(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $statusService = $this->createMock(StatusService::class);
        $orderService = $this->createMock(OrderService::class);
        $acceptedStatus = $this->createMock(Status::class);

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('accepted', 'aceito', 'delivery')
            ->willReturn($acceptedStatus);

        $order = new Order();
        $order->setApp('DELIVERY');
        $order->setOrderType(Order::ORDER_TYPE_DELIVERY);

        $orderService
            ->expects(self::never())
            ->method('convertDraftOrderToSale');

        $orderService
            ->expects(self::never())
            ->method('dispatchOrderCreated');

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($acceptedStatus): bool {
                return $entity instanceof Order
                    && $entity->getStatus() === $acceptedStatus
                    && $entity->getOrderType() === Order::ORDER_TYPE_DELIVERY;
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
        self::assertSame(Order::ORDER_TYPE_DELIVERY, $order->getOrderType());
        self::assertSame($acceptedStatus, $order->getStatus());
    }

    public function testDeliveredDeliveryOrderUsesDeliveryClosedStatus(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $statusService = $this->createMock(StatusService::class);
        $orderService = $this->createMock(OrderService::class);
        $closedStatus = $this->createMock(Status::class);

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('closed', 'closed', 'delivery')
            ->willReturn($closedStatus);

        $order = new Order();
        $order->setApp('DELIVERY');
        $order->setOrderType(Order::ORDER_TYPE_DELIVERY);

        $orderService
            ->expects(self::never())
            ->method('convertDraftOrderToSale');

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($closedStatus): bool {
                return $entity instanceof Order
                    && $entity->getStatus() === $closedStatus
                    && $entity->getOrderType() === Order::ORDER_TYPE_DELIVERY;
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
        self::assertSame(Order::ORDER_TYPE_DELIVERY, $order->getOrderType());
        self::assertSame($closedStatus, $order->getStatus());
    }

    public function testCancelDeliveryOrderUsesDeliveryCanceledStatus(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $statusService = $this->createMock(StatusService::class);
        $orderService = $this->createMock(OrderService::class);
        $canceledStatus = $this->createMock(Status::class);

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('canceled', 'canceled', 'delivery')
            ->willReturn($canceledStatus);

        $order = new Order();
        $order->setApp('DELIVERY');
        $order->setOrderType(Order::ORDER_TYPE_DELIVERY);

        $orderService
            ->expects(self::never())
            ->method('convertDraftOrderToSale');

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($canceledStatus): bool {
                return $entity instanceof Order
                    && $entity->getStatus() === $canceledStatus
                    && $entity->getOrderType() === Order::ORDER_TYPE_DELIVERY;
            }));

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service = new OrderActionService(
            $entityManager,
            $statusService,
            $orderService,
        );

        $result = $service->cancel($order);

        self::assertSame(0, $result['errno']);
        self::assertSame('ok', $result['errmsg']);
        self::assertSame(Order::ORDER_TYPE_DELIVERY, $order->getOrderType());
        self::assertSame($canceledStatus, $order->getStatus());
    }
}
