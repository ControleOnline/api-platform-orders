<?php

namespace ControleOnline\Orders\Tests\Controller;

use ControleOnline\Controller\OrderConferenceController;
use ControleOnline\Entity\Order;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderService;
use PHPUnit\Framework\TestCase;

class OrderConferenceControllerTest extends TestCase
{
    public function testReturnsConferencePayloadWhenOrderExists(): void
    {
        $order = new Order();
        $order->setExternalCode('570015');
        $order->setOrderType(Order::ORDER_TYPE_SALE);

        $orderService = $this->createMock(OrderService::class);
        $orderService
            ->expects(self::once())
            ->method('findOrderById')
            ->with(72128)
            ->willReturn($order);
        $orderService
            ->expects(self::once())
            ->method('normalizeOrderProductGroupLinks')
            ->with($order)
            ->willReturn(false);

        $hydratorService = $this->createMock(HydratorService::class);
        $hydratorService
            ->expects(self::once())
            ->method('item')
            ->with(Order::class, 72128, ['order:read', 'order_conference:read'])
            ->willReturn([
                'id' => 72128,
                'orderProducts' => [],
            ]);

        $controller = new OrderConferenceController($hydratorService, $orderService);
        $response = $controller->__invoke(72128);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'id' => 72128,
                'orderProducts' => [],
            ],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testReturns404WhenConferenceOrderIsMissing(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService
            ->expects(self::once())
            ->method('findOrderById')
            ->with(72128)
            ->willReturn(null);
        $orderService
            ->expects(self::never())
            ->method('normalizeOrderProductGroupLinks');

        $hydratorService = $this->createMock(HydratorService::class);
        $hydratorService
            ->expects(self::never())
            ->method('item');

        $controller = new OrderConferenceController($hydratorService, $orderService);
        $response = $controller->__invoke(72128);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Order not found'],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
