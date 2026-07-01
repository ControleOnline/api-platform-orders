<?php

namespace ControleOnline\Orders\Tests\Controller;

use ControleOnline\Controller\OrderDetailsController;
use ControleOnline\Entity\Order;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderService;
use PHPUnit\Framework\TestCase;

class OrderDetailsControllerTest extends TestCase
{
    public function testReturnsHydratedPayloadWhenOrderExists(): void
    {
        $order = new Order();
        $order->setExternalCode('570002');
        $order->setOrderType(Order::ORDER_TYPE_TABLE);

        $orderService = $this->createMock(OrderService::class);
        $orderService
            ->expects(self::once())
            ->method('findOrderById')
            ->with(71760)
            ->willReturn($order);

        $hydratorService = $this->createMock(HydratorService::class);
        $hydratorService
            ->expects(self::once())
            ->method('item')
            ->with(Order::class, 71760, 'order_details:read')
            ->willReturn([
                'id' => 71760,
                'mainOrder' => [
                    'id' => 71234,
                    'externalCode' => '570002',
                ],
            ]);

        $controller = new OrderDetailsController($hydratorService, $orderService);
        $response = $controller->__invoke(71760);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'id' => 71760,
                'mainOrder' => [
                    'id' => 71234,
                    'externalCode' => '570002',
                ],
            ],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testReturns404WhenOrderIsMissing(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService
            ->expects(self::once())
            ->method('findOrderById')
            ->with(71760)
            ->willReturn(null);

        $hydratorService = $this->createMock(HydratorService::class);
        $hydratorService
            ->expects(self::never())
            ->method('item');

        $controller = new OrderDetailsController($hydratorService, $orderService);
        $response = $controller->__invoke(71760);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Order not found'],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
