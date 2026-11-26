<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\{Order, People, Status};
use ControleOnline\Service\{OrderActionService, OrderService, StatusService};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class OrderDraftDiscardTest extends TestCase
{
    private function service(Order $order, ?callable $refresh = null): OrderActionService
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(fn ($callback) => $callback());
        $manager->expects(self::once())->method('refresh')->with($order, LockMode::PESSIMISTIC_WRITE)
            ->willReturnCallback(fn () => $refresh ? $refresh($order) : null);
        return $this->getMockBuilder(OrderActionService::class)->setConstructorArgs([
            $manager, $this->createStub(StatusService::class), $this->createStub(OrderService::class),
        ])->onlyMethods(['cancel'])->getMock();
    }

    public function testDelegatesDraftToExistingAuditedCancellationWithActorAndCompany(): void
    {
        $order = (new Order())->setOrderType('cart')->setMainOrderId(10);
        $actor = new People();
        $company = new People();
        $order->setProvider($company);
        $service = $this->service($order);
        $service->expects(self::once())->method('cancel')->with($order, null, 'Descartar', $actor, $company)
            ->willReturn(['errno' => 0]);
        self::assertSame(['errno' => 0], $service->discardDraft($order, 10, 'Descartar', $actor, $company));
    }

    public function testRejectsDraftThatWasConfirmedByAnotherRequestBeforeTheLock(): void
    {
        $order = (new Order())->setOrderType('cart')->setMainOrderId(10);
        $service = $this->service($order, fn (Order $latest) => $latest->setOrderType('sale'));
        $service->expects(self::never())->method('cancel');
        $this->expectException(\InvalidArgumentException::class);
        $service->discardDraft($order, 10);
    }

    public function testRejectsDraftTransferredToAnotherTab(): void
    {
        $order = (new Order())->setOrderType('cart')->setMainOrderId(10);
        $service = $this->service($order, fn (Order $latest) => $latest->setMainOrderId(11));
        $service->expects(self::never())->method('cancel');
        $this->expectException(\InvalidArgumentException::class);
        $service->discardDraft($order, 10);
    }

    public function testRejectsAlreadyCanceledDraft(): void
    {
        $status = (new Status())->setRealStatus('canceled');
        $order = (new Order())->setOrderType('cart')->setMainOrderId(10)->setStatus($status);
        $service = $this->service($order);
        $service->expects(self::never())->method('cancel');
        $this->expectException(\InvalidArgumentException::class);
        $service->discardDraft($order, 10);
    }
}
