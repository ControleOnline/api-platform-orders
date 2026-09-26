<?php

namespace ControleOnline\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\Status;
use ControleOnline\Service\MarkOrderAsPaidService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\StatusService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** app-community#909 */
class MarkOrderAsPaidServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PeopleService&MockObject $peopleService;
    private StatusService&MockObject $statusService;
    private MarkOrderAsPaidService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->peopleService = $this->createMock(PeopleService::class);
        $this->statusService = $this->createMock(StatusService::class);
        $this->service = new MarkOrderAsPaidService(
            $this->em,
            $this->peopleService,
            $this->statusService,
            null,
        );
    }

    public function testFallbackSettleUsesOpenPaid(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getPrice')->willReturn(0.0);
        $order->method('getInvoice')->willReturn(new ArrayCollection([]));
        $paid = $this->createMock(Status::class);
        $this->statusService->expects($this->once())
            ->method('discoveryStatus')
            ->with('open', 'paid', 'order')
            ->willReturn($paid);
        $order->expects($this->once())->method('setStatus')->with($paid);
        $this->em->expects($this->once())->method('persist')->with($order);
        $m = new ReflectionMethod(MarkOrderAsPaidService::class, 'fallbackSettleOrder');
        $m->setAccessible(true);
        $m->invoke($this->service, $order, 10.0);
    }

    public function testFallbackSettleDoesNotUseClosedClosed(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getPrice')->willReturn(0.0);
        $order->method('getInvoice')->willReturn(new ArrayCollection([]));
        $this->statusService->method('discoveryStatus')->willReturn(null);
        $order->expects($this->never())->method('setStatus');
        $this->expectException(BadRequestHttpException::class);
        $m = new ReflectionMethod(MarkOrderAsPaidService::class, 'fallbackSettleOrder');
        $m->setAccessible(true);
        $m->invoke($this->service, $order, 10.0);
    }
}
