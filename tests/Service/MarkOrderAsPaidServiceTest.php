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

/**
 * app-community#909 — mark-as-paid must not settle to generic closed/closed.
 */
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

    public function testFallbackSettleUsesOpenPaidNotClosedClosed(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getPrice')->willReturn(10.0);
        $order->method('getInvoice')->willReturn(new ArrayCollection([]));

        $paidStatus = $this->createMock(Status::class);
        $paidStatus->method('getStatus')->willReturn('paid');
        $paidStatus->method('getRealStatus')->willReturn('open');

        $this->statusService->expects($this->once())
            ->method('discoveryStatus')
            ->with('open', 'paid', 'order')
            ->willReturn($paidStatus);

        $order->expects($this->once())->method('setStatus')->with($paidStatus);
        $this->em->expects($this->once())->method('persist')->with($order);

        $this->invokeFallback($order, 10.0);
    }

    public function testFallbackSettleNeverUsesClosedClosedWhenOpenPaidMissing(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getPrice')->willReturn(10.0);
        $order->method('getInvoice')->willReturn(new ArrayCollection([]));

        $this->statusService->method('discoveryStatus')->willReturnCallback(
            static function (string $real, string $name, string $context) {
                // Simulate tenant without open/paid and without closed/paid (closed/closed exists but must not be used).
                if ($real === 'closed' && $name === 'closed' && $context === 'order') {
                    $s = new Status();
                    return $s;
                }

                return null;
            }
        );

        $order->expects($this->never())->method('setStatus');
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/open\\/paid/i');

        $this->invokeFallback($order, 10.0);
    }

    private function invokeFallback(Order $order, float $justPaid): void
    {
        $method = new ReflectionMethod(MarkOrderAsPaidService::class, 'fallbackSettleOrder');
        $method->setAccessible(true);
        $method->invoke($this->service, $order, $justPaid);
    }
}
