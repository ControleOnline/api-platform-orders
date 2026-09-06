<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\OrderProductFulfillment;
use ControleOnline\Entity\People;
use ControleOnline\Repository\OrderProductFulfillmentRepository;
use ControleOnline\Service\OrderFulfillmentService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AllowMockObjectsWithoutExpectations]
class OrderFulfillmentServiceTest extends TestCase
{
    public function testClientCannotCompleteFulfillment(): void
    {
        $service = $this->buildService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(OrderProductFulfillmentRepository::class),
        );

        $this->expectException(AccessDeniedHttpException::class);
        $service->execute([
            'orderProductId' => 1,
            'action' => 'served',
            'idempotencyKey' => 'k1',
        ], null, true);
    }

    public function testRejectsInvalidAction(): void
    {
        $service = $this->buildService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(OrderProductFulfillmentRepository::class),
        );

        $this->expectException(BadRequestHttpException::class);
        $service->execute([
            'orderProductId' => 1,
            'action' => 'invalid',
            'idempotencyKey' => 'k1',
        ], null, false);
    }

    public function testRejectsMissingIdempotencyKey(): void
    {
        $service = $this->buildService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(OrderProductFulfillmentRepository::class),
        );

        $this->expectException(BadRequestHttpException::class);
        $service->execute([
            'orderProductId' => 1,
            'action' => 'served',
            'idempotencyKey' => '',
        ], null, false);
    }

    public function testIdempotentReplayReturnsExisting(): void
    {
        $existing = new OrderProductFulfillment();
        $existing->setAction('served');
        $existing->setQuantity(1.0);
        $existing->setIdempotencyKey('same-key');

        $repo = $this->createMock(OrderProductFulfillmentRepository::class);
        $repo->expects(self::once())
            ->method('findByIdempotencyKey')
            ->with('same-key')
            ->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $service = $this->buildService($em, $repo);
        $result = $service->execute([
            'orderProductId' => 10,
            'action' => 'served',
            'idempotencyKey' => 'same-key',
            'quantity' => 1,
        ], null, false);

        self::assertSame($existing, $result);
    }

    public function testRejectsWhenQuantityExceedsRemaining(): void
    {
        $order = $this->createMock(Order::class);
        $orderProduct = $this->createMock(OrderProduct::class);
        $orderProduct->method('getOrderProduct')->willReturn(null);
        $orderProduct->method('getOrder')->willReturn($order);
        $orderProduct->method('getQuantity')->willReturn(2.0);
        $orderProduct->method('getId')->willReturn(5);

        $opRepo = $this->createMock(EntityRepository::class);
        $opRepo->method('find')->with(5)->willReturn($orderProduct);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(OrderProduct::class)->willReturn($opRepo);

        $repo = $this->createMock(OrderProductFulfillmentRepository::class);
        $repo->method('findByIdempotencyKey')->willReturn(null);
        $repo->method('sumCompletedQuantity')->with($orderProduct)->willReturn(1.5);

        $service = $this->buildService($em, $repo);

        $this->expectException(BadRequestHttpException::class);
        $service->execute([
            'orderProductId' => 5,
            'action' => 'served',
            'idempotencyKey' => 'k-over',
            'quantity' => 1.0,
        ], null, false);
    }

    public function testRejectsChildCustomization(): void
    {
        $parent = $this->createMock(OrderProduct::class);
        $child = $this->createMock(OrderProduct::class);
        $child->method('getOrderProduct')->willReturn($parent);

        $opRepo = $this->createMock(EntityRepository::class);
        $opRepo->method('find')->with(9)->willReturn($child);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(OrderProduct::class)->willReturn($opRepo);

        $repo = $this->createMock(OrderProductFulfillmentRepository::class);
        $repo->method('findByIdempotencyKey')->willReturn(null);

        $service = $this->buildService($em, $repo);

        $this->expectException(BadRequestHttpException::class);
        $service->execute([
            'orderProductId' => 9,
            'action' => 'served',
            'idempotencyKey' => 'k-child',
        ], null, false);
    }

    public function testSuccessfulUnitFulfillmentPersistsLedger(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(100);

        $orderProduct = $this->createMock(OrderProduct::class);
        $orderProduct->method('getOrderProduct')->willReturn(null);
        $orderProduct->method('getOrder')->willReturn($order);
        $orderProduct->method('getQuantity')->willReturn(3.0);
        $orderProduct->method('getId')->willReturn(7);

        $opRepo = $this->createMock(EntityRepository::class);
        $opRepo->method('find')->with(7)->willReturn($orderProduct);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(OrderProduct::class)->willReturn($opRepo);
        $em->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity): bool {
                return $entity instanceof OrderProductFulfillment
                    && $entity->getAction() === 'served'
                    && $entity->getQuantity() === 1.0
                    && $entity->getIdempotencyKey() === 'k-ok'
                    && $entity->getStatus() === OrderProductFulfillment::STATUS_COMPLETED;
            }));
        $em->expects(self::once())->method('flush');

        $repo = $this->createMock(OrderProductFulfillmentRepository::class);
        $repo->method('findByIdempotencyKey')->willReturn(null);
        $repo->method('sumCompletedQuantity')->with($orderProduct)->willReturn(0.0);

        $actor = $this->createMock(People::class);
        $service = $this->buildService($em, $repo);

        $entry = $service->execute([
            'orderProductId' => 7,
            'action' => 'served',
            'quantity' => 1,
            'idempotencyKey' => 'k-ok',
            'deviceOrigin' => 'pos-1',
        ], $actor, false);

        self::assertInstanceOf(OrderProductFulfillment::class, $entry);
        self::assertSame('served', $entry->getAction());
        self::assertSame(1.0, $entry->getQuantity());
        self::assertSame($actor, $entry->getActor());
        self::assertSame('pos-1', $entry->getDeviceOrigin());
    }

    public function testNotFoundOrderProduct(): void
    {
        $opRepo = $this->createMock(EntityRepository::class);
        $opRepo->method('find')->with(999)->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(OrderProduct::class)->willReturn($opRepo);

        $repo = $this->createMock(OrderProductFulfillmentRepository::class);
        $repo->method('findByIdempotencyKey')->willReturn(null);

        $service = $this->buildService($em, $repo);

        $this->expectException(NotFoundHttpException::class);
        $service->execute([
            'orderProductId' => 999,
            'action' => 'delivered',
            'idempotencyKey' => 'k-nf',
        ], null, false);
    }

    private function buildService(
        EntityManagerInterface $em,
        OrderProductFulfillmentRepository $repo,
    ): OrderFulfillmentService {
        return new OrderFulfillmentService($em, $repo);
    }
}
