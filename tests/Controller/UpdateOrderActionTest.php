<?php

namespace ControleOnline\Orders\Tests\Controller;

use ControleOnline\Controller\UpdateOrderAction;
use ControleOnline\Entity\Order;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class UpdateOrderActionTest extends TestCase
{
    public function testReturnsHydratedPayloadWhenOrderExists(): void
    {
        $order = new Order();
        $order->setOrderType(Order::ORDER_TYPE_CART);
        $order->setComments('Mesa 4');
        $this->setEntityId(Order::class, $order, 78112);

        $orderService = $this->createMock(OrderService::class);
        $orderService
            ->expects(self::once())
            ->method('findOrderById')
            ->with(78112)
            ->willReturn($order);
        $orderService
            ->expects(self::once())
            ->method('updateOrderFromPayload')
            ->with($order, [
                'comments' => 'Mesa 4',
                'orderType' => 'sale',
            ])
            ->willReturn($order);

        $hydratorService = $this->createMock(HydratorService::class);
        $hydratorService
            ->expects(self::once())
            ->method('item')
            ->with(Order::class, 78112, 'order:write')
            ->willReturn([
                'id' => 78112,
                'orderType' => 'sale',
                'comments' => 'Mesa 4',
            ]);

        $controller = new UpdateOrderAction($hydratorService, $orderService);
        $response = $controller->__invoke(
            Request::create(
                '/orders/78112',
                'PUT',
                [],
                [],
                [],
                [],
                '{"comments":"Mesa 4","orderType":"sale"}',
            ),
            78112,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'id' => 78112,
                'orderType' => 'sale',
                'comments' => 'Mesa 4',
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
            ->with(78112)
            ->willReturn(null);
        $orderService
            ->expects(self::never())
            ->method('updateOrderFromPayload');

        $hydratorService = $this->createMock(HydratorService::class);
        $hydratorService
            ->expects(self::never())
            ->method('item');
        $hydratorService
            ->expects(self::once())
            ->method('error')
            ->with($this->callback(
                fn (\Throwable $exception) => $exception->getMessage() === 'Order not found'
            ))
            ->willReturn([
                '@type' => 'Error',
                'hydra:title' => 'An error occurred',
                'hydra:description' => 'Order not found',
            ]);

        $controller = new UpdateOrderAction($hydratorService, $orderService);
        $response = $controller->__invoke(
            Request::create('/orders/78112', 'PUT', [], [], [], [], '{}'),
            78112,
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            [
                '@type' => 'Error',
                'hydra:title' => 'An error occurred',
                'hydra:description' => 'Order not found',
            ],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function setEntityId(string $className, object $entity, int $id): void
    {
        $property = new \ReflectionProperty($className, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
