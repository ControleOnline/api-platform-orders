<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\OrderProductAdjustment;
use ControleOnline\Entity\People;
use ControleOnline\Repository\OrderProductAdjustmentRepository;
use ControleOnline\Repository\OrderProductFulfillmentRepository;
use ControleOnline\Service\OrderProductAdjustmentService;
use ControleOnline\Service\OrderService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderProductAdjustmentServiceTest extends TestCase
{
    private function makeService(
        EntityManagerInterface $em,
        OrderProductAdjustmentRepository $adjRepo,
        ?OrderProductFulfillmentRepository $fulRepo = null,
    ): OrderProductAdjustmentService {
        return new OrderProductAdjustmentService(
            $em,
            $adjRepo,
            $fulRepo,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testRequiresReason(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(OrderProductAdjustmentRepository::class);
        $repo->method('findByIdempotencyKey')->willReturn(null);

        $service = $this->makeService($em, $repo);
        $result = $service->preview([
            'orderProductId' => 1,
            'quantityAfter' => 1,
            'reason' => '',
            'idempotencyKey' => 'k1',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(20003, $result['errno']);
    }

    public function testRejectsCartOrder(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderType')->willReturn(OrderService::ORDER_TYPE_CART);
        $order->method('getId')->willReturn(10);

        $op = $this->createMock(OrderProduct::class);
        $op->method('getId')->willReturn(1);
        $op->method('getParentProduct')->willReturn(null);
        $op->method('getOrder')->willReturn($order);
        $op->method('getQuantity')->willReturn(2.0);

        $opRepo = $this->createMock(EntityRepository::class);
        $opRepo->method('find')->willReturn($op);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(OrderProduct::class)->willReturn($opRepo);

        $repo = $this->createMock(OrderProductAdjustmentRepository::class);
        $repo->method('findByIdempotencyKey')->willReturn(null);

        $service = $this->makeService($em, $repo);
        $result = $service->preview([
            'orderProductId' => 1,
            'quantityAfter' => 1,
            'reason' => 'fix',
            'idempotencyKey' => 'k-cart',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(20008, $result['errno']);
    }

    public function testCannotReduceBelowFulfilled(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderType')->willReturn(OrderService::ORDER_TYPE_SALE);
        $order->method('getId')->willReturn(20);

        $op = $this->createMock(OrderProduct::class);
        $op->method('getId')->willReturn(5);
        $op->method('getParentProduct')->willReturn(null);
        $op->method('getOrder')->willReturn($order);
        $op->method('getQuantity')->willReturn(3.0);
        $op->method('getOrderProductQueues')->willReturn([]);

        $opRepo = $this->createMock(EntityRepository::class);
        $opRepo->method('find')->willReturn($op);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(OrderProduct::class)->willReturn($opRepo);

        $adjRepo = $this->createMock(OrderProductAdjustmentRepository::class);
        $adjRepo->method('findByIdempotencyKey')->willReturn(null);

        $fulRepo = $this->createMock(OrderProductFulfillmentRepository::class);
        $fulRepo->method('sumCompletedQuantity')->willReturn(2.0);

        $service = $this->makeService($em, $adjRepo, $fulRepo);
        $result = $service->preview([
            'orderProductId' => 5,
            'quantityAfter' => 1.0,
            'reason' => 'over-sold',
            'idempotencyKey' => 'k-ful',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(20010, $result['errno']);
    }

    public function testClientDeniedOnCommit(): void
    {
        $client = $this->createMock(People::class);
        $client->method('getId')->willReturn(99);
        $provider = $this->createMock(People::class);
        $provider->method('getId')->willReturn(1);

        $order = $this->createMock(Order::class);
        $order->method('getOrderType')->willReturn(OrderService::ORDER_TYPE_SALE);
        $order->method('getId')->willReturn(30);
        $order->method('getClient')->willReturn($client);
        $order->method('getProvider')->willReturn($provider);

        $op = $this->createMock(OrderProduct::class);
        $op->method('getId')->willReturn(7);
        $op->method('getParentProduct')->willReturn(null);
        $op->method('getOrder')->willReturn($order);
        $op->method('getQuantity')->willReturn(2.0);

        $opRepo = $this->createMock(EntityRepository::class);
        $opRepo->method('find')->willReturn($op);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(OrderProduct::class)->willReturn($opRepo);

        $adjRepo = $this->createMock(OrderProductAdjustmentRepository::class);
        $adjRepo->method('findByIdempotencyKey')->willReturn(null);

        $service = $this->makeService($em, $adjRepo);
        $result = $service->commit([
            'orderProductId' => 7,
            'quantityAfter' => 1.0,
            'reason' => 'client-try',
            'idempotencyKey' => 'k-client',
        ], $client, true);

        self::assertFalse($result['ok']);
        self::assertSame(20009, $result['errno']);
    }

    public function testPreviewDoesNotMutate(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderType')->willReturn(OrderService::ORDER_TYPE_SALE);
        $order->method('getId')->willReturn(40);

        $op = $this->createMock(OrderProduct::class);
        $op->method('getId')->willReturn(9);
        $op->method('getParentProduct')->willReturn(null);
        $op->method('getOrder')->willReturn($order);
        $op->method('getQuantity')->willReturn(4.0);
        $op->method('getOrderProductQueues')->willReturn([]);

        $opRepo = $this->createMock(EntityRepository::class);
        $opRepo->method('find')->willReturn($op);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(OrderProduct::class)->willReturn($opRepo);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $adjRepo = $this->createMock(OrderProductAdjustmentRepository::class);
        $adjRepo->method('findByIdempotencyKey')->willReturn(null);

        $fulRepo = $this->createMock(OrderProductFulfillmentRepository::class);
        $fulRepo->method('sumCompletedQuantity')->willReturn(0.0);

        $service = $this->makeService($em, $adjRepo, $fulRepo);
        $result = $service->preview([
            'orderProductId' => 9,
            'quantityAfter' => 3.0,
            'reason' => 'customer request',
            'idempotencyKey' => 'k-prev',
        ]);

        self::assertTrue($result['ok']);
        self::assertSame(-1.0, $result['preview']['delta']);
        self::assertArrayNotHasKey('entry', $result);
    }

    public function testCommitPersistsAuditAndQuantity(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderType')->willReturn(OrderService::ORDER_TYPE_SALE);
        $order->method('getId')->willReturn(50);

        $op = $this->createMock(OrderProduct::class);
        $op->method('getId')->willReturn(11);
        $op->method('getParentProduct')->willReturn(null);
        $op->method('getOrder')->willReturn($order);
        $op->method('getQuantity')->willReturn(5.0);
        $op->method('getOrderProductQueues')->willReturn([]);
        $op->expects(self::once())->method('setQuantity')->with(3.0);

        $opRepo = $this->createMock(EntityRepository::class);
        $opRepo->method('find')->willReturn($op);

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(OrderProduct::class)->willReturn($opRepo);
        $em->method('getConnection')->willReturn($connection);
        $em->expects(self::once())->method('beginTransaction');
        $em->expects(self::once())->method('refresh')->with($op);
        $em->expects(self::exactly(2))->method('persist');
        $em->expects(self::once())->method('flush');
        $em->expects(self::once())->method('commit');

        $adjRepo = $this->createMock(OrderProductAdjustmentRepository::class);
        $adjRepo->method('findByIdempotencyKey')->willReturn(null);

        $fulRepo = $this->createMock(OrderProductFulfillmentRepository::class);
        $fulRepo->method('sumCompletedQuantity')->willReturn(1.0);

        $actor = $this->createMock(People::class);
        $actor->method('getId')->willReturn(3);

        $service = $this->makeService($em, $adjRepo, $fulRepo);
        $result = $service->commit([
            'orderProductId' => 11,
            'quantityAfter' => 3.0,
            'reason' => 'partial cancel',
            'idempotencyKey' => 'k-commit',
            'deviceId' => 2,
        ], $actor, true);

        self::assertTrue($result['ok']);
        self::assertFalse($result['replayed']);
        self::assertInstanceOf(OrderProductAdjustment::class, $result['entry']);
        self::assertSame(5.0, $result['entry']->getQuantityBefore());
        self::assertSame(3.0, $result['entry']->getQuantityAfter());
        self::assertSame('partial cancel', $result['entry']->getReason());
    }
}
