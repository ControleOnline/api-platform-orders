<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\Status;
use ControleOnline\Service\OrderActionService;
use ControleOnline\Service\OrderCheckoutIdempotencyStore;
use ControleOnline\Service\OrderCheckoutService;
use ControleOnline\Service\OrderInvoiceService;
use ControleOnline\Service\OrderService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class OrderCheckoutServiceTest extends TestCase
{
    public function testCanceledOrderIsRefusedAsCanceledOutcome(): void
    {
        $status = $this->createMock(Status::class);
        $status->method('getRealStatus')->willReturn('canceled');
        $status->method('getStatus')->willReturn('canceled');

        $order = new Order();
        $order->setOrderType(Order::ORDER_TYPE_CART);
        $order->setStatus($status);

        $service = $this->buildService(
            entityManager: $this->createMock(EntityManagerInterface::class),
            orderService: $this->createMock(OrderService::class),
            orderActionService: $this->createMock(OrderActionService::class),
        );

        $result = $service->checkout($order, ['confirm' => true]);

        self::assertSame(OrderCheckoutService::OUTCOME_CANCELED, $result['outcome']);
        self::assertSame(1, $result['errno']);
    }

    public function testCheckoutPromotesCartToSaleAndConfirms(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->method('isTransactionActive')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $order = new Order();
        $order->setOrderType(Order::ORDER_TYPE_CART);
        $order->setApp('POS');
        $order->setPrice(50);

        $preparing = $this->createMock(Status::class);
        $preparing->method('getRealStatus')->willReturn('open');
        $preparing->method('getStatus')->willReturn('preparing');

        $orderService = $this->createMock(OrderService::class);
        $orderService
            ->expects(self::once())
            ->method('convertDraftOrderToSale')
            ->with($order)
            ->willReturnCallback(static function (Order $order): bool {
                $order->setOrderType(Order::ORDER_TYPE_SALE);
                return true;
            });
        $orderService->expects(self::once())->method('dispatchOrderCreated')->with($order);

        $orderActionService = $this->createMock(OrderActionService::class);
        $orderActionService
            ->expects(self::once())
            ->method('confirm')
            ->with($order)
            ->willReturnCallback(static function (Order $order) use ($preparing): array {
                $order->setStatus($preparing);
                return ['errno' => 0, 'errmsg' => 'ok'];
            });

        $service = $this->buildService($entityManager, $orderService, $orderActionService);
        $result = $service->checkout($order, [
            'confirm' => true,
            'mode' => 'counter',
            'idempotencyKey' => 'idem-1',
        ]);

        self::assertSame(OrderCheckoutService::OUTCOME_SUCCESS, $result['outcome']);
        self::assertSame(0, $result['errno']);
        self::assertTrue($result['promotedToSale']);
        self::assertSame(Order::ORDER_TYPE_SALE, $result['order']['orderType']);
        self::assertTrue($result['confirmation']['confirmed']);
        self::assertSame('counter', $result['mode']);
    }

    public function testRepeatedIdempotentCallReplaysWithoutSecondPromotion(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->method('isTransactionActive')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('persist');
        $entityManager->method('flush');

        $order = new Order();
        $order->setOrderType(Order::ORDER_TYPE_CART);
        $order->setApp('POS');

        $orderService = $this->createMock(OrderService::class);
        $orderService
            ->expects(self::once())
            ->method('convertDraftOrderToSale')
            ->willReturnCallback(static function (Order $order): bool {
                $order->setOrderType(Order::ORDER_TYPE_SALE);
                return true;
            });
        $orderService->method('dispatchOrderCreated');

        $orderActionService = $this->createMock(OrderActionService::class);
        $orderActionService
            ->expects(self::once())
            ->method('confirm')
            ->willReturn(['errno' => 0, 'errmsg' => 'ok']);

        $service = $this->buildService($entityManager, $orderService, $orderActionService);
        $payload = ['confirm' => true, 'idempotencyKey' => 'same-key'];

        $first = $service->checkout($order, $payload);
        $second = $service->checkout($order, $payload);

        self::assertSame(OrderCheckoutService::OUTCOME_SUCCESS, $first['outcome']);
        self::assertTrue($second['idempotentReplay'] ?? false);
        self::assertSame($first['outcome'], $second['outcome']);
    }

    public function testIdempotencyKeyWithDifferentPayloadReturnsConflict(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('beginTransaction');
        $connection->method('commit');
        $connection->method('isTransactionActive')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('persist');
        $entityManager->method('flush');

        $order = new Order();
        $order->setOrderType(Order::ORDER_TYPE_SALE);

        $orderService = $this->createMock(OrderService::class);
        $orderActionService = $this->createMock(OrderActionService::class);
        $orderActionService->method('confirm')->willReturn(['errno' => 0, 'errmsg' => 'ok']);

        $service = $this->buildService($entityManager, $orderService, $orderActionService);

        $service->checkout($order, ['confirm' => true, 'idempotencyKey' => 'k1']);
        $conflict = $service->checkout($order, ['confirm' => false, 'idempotencyKey' => 'k1']);

        self::assertSame(OrderCheckoutService::OUTCOME_CONFLICT, $conflict['outcome']);
        self::assertSame(409, $conflict['errno']);
    }

    public function testConfirmationRefusalDoesNotLeavePartialCommit(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');
        $connection->method('isTransactionActive')->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $order = new Order();
        $order->setOrderType(Order::ORDER_TYPE_CART);
        $order->setApp('SHOP');

        $orderService = $this->createMock(OrderService::class);
        $orderService
            ->method('convertDraftOrderToSale')
            ->willReturnCallback(static function (Order $order): bool {
                $order->setOrderType(Order::ORDER_TYPE_SALE);
                return true;
            });

        $orderActionService = $this->createMock(OrderActionService::class);
        $orderActionService
            ->method('confirm')
            ->willReturn(['errno' => 10002, 'errmsg' => 'Pedido do Shop sem endereco de entrega valido.']);

        $service = $this->buildService($entityManager, $orderService, $orderActionService);
        $result = $service->checkout($order, ['confirm' => true]);

        self::assertSame(OrderCheckoutService::OUTCOME_REFUSED, $result['outcome']);
        self::assertSame(10002, $result['errno']);
    }

    private function buildService(
        EntityManagerInterface $entityManager,
        OrderService $orderService,
        OrderActionService $orderActionService,
        ?OrderInvoiceService $orderInvoiceService = null,
    ): OrderCheckoutService {
        return new OrderCheckoutService(
            $entityManager,
            $orderService,
            $orderActionService,
            new OrderCheckoutIdempotencyStore(),
            $orderInvoiceService,
        );
    }
}
