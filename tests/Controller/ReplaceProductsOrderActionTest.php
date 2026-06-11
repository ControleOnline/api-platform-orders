<?php

namespace ControleOnline\Orders\Tests\Controller;

use ControleOnline\Controller\ReplaceProductsOrderAction;
use ControleOnline\Entity\Order;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\OrderService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ReplaceProductsOrderActionTest extends TestCase
{
    public function testReturnsHydratedPayloadWhenOrderExists(): void
    {
        $order = new Order();
        $this->setEntityId(Order::class, $order, 78112);

        $orderService = $this->createMock(OrderService::class);
        $orderService
            ->expects(self::once())
            ->method('findOrderById')
            ->with(78112)
            ->willReturn($order);

        $orderProductService = $this->createMock(OrderProductService::class);
        $orderProductService
            ->expects(self::once())
            ->method('replaceProductsToOrderFromContent')
            ->with($order, '[{"product":"/products/55","quantity":1}]');

        $hydratorService = $this->createMock(HydratorService::class);
        $hydratorService
            ->expects(self::once())
            ->method('item')
            ->with(Order::class, 78112, 'order:write')
            ->willReturn([
                'id' => 78112,
                'orderProducts' => [
                    ['id' => 99, 'product' => ['id' => 55]],
                ],
            ]);

        $controller = new ReplaceProductsOrderAction(
            $hydratorService,
            $orderProductService,
            $orderService,
        );

        $response = $controller->__invoke(
            Request::create(
                '/orders/78112/replace-products',
                'PUT',
                [],
                [],
                [],
                [],
                '[{"product":"/products/55","quantity":1}]',
            ),
            78112,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'id' => 78112,
                'orderProducts' => [
                    ['id' => 99, 'product' => ['id' => 55]],
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
            ->with(78112)
            ->willReturn(null);

        $orderProductService = $this->createMock(OrderProductService::class);
        $orderProductService
            ->expects(self::never())
            ->method('replaceProductsToOrderFromContent');

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

        $controller = new ReplaceProductsOrderAction(
            $hydratorService,
            $orderProductService,
            $orderService,
        );

        $response = $controller->__invoke(
            Request::create('/orders/78112/replace-products', 'PUT', [], [], [], [], '[]'),
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
