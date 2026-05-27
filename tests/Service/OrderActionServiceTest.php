<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
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
}
