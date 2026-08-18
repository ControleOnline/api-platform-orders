<?php

namespace ControleOnline\Orders\Tests\EventSubscriber;

use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\Status;
use ControleOnline\EventSubscriber\OrderChargeAuthorizationSubscriber;
use ControleOnline\Service\OrderCommercialContextService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AllowMockObjectsWithoutExpectations]
class OrderChargeAuthorizationSubscriberTest extends TestCase
{
    public function testDirectInvoiceRouteUsesBackendChargeContract(): void
    {
        $order = new Order();
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('find')
            ->with(45)
            ->willReturn($order);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->expects(self::once())
            ->method('getRepository')
            ->with(Order::class)
            ->willReturn($repository);
        $commercialContextService = $this->createMock(OrderCommercialContextService::class);
        $commercialContextService
            ->expects(self::once())
            ->method('assertChargeAllowed')
            ->with($order, OrderCommercialContextService::CHARGE_MODE_LOCAL);

        $request = Request::create(
            '/invoices',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['order' => '/orders/45']) ?: '{}',
        );
        $request->headers->set('ORDER-CHARGE-MODE', 'remote');

        $subscriber = new OrderChargeAuthorizationSubscriber(
            $manager,
            $commercialContextService,
        );
        $subscriber->onKernelController($this->createControllerEvent($request));
    }

    public function testUnrelatedInvoiceDoesNotInvokeOrderChargeContract(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getRepository');
        $commercialContextService = $this->createMock(OrderCommercialContextService::class);
        $commercialContextService->expects(self::never())->method('assertChargeAllowed');
        $request = Request::create(
            '/invoices',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['description' => 'Invoice without order']) ?: '{}',
        );

        $subscriber = new OrderChargeAuthorizationSubscriber(
            $manager,
            $commercialContextService,
        );
        $subscriber->onKernelController($this->createControllerEvent($request));
    }

    public function testClosingLinkedInvoiceAlsoUsesBackendChargeContract(): void
    {
        $order = new Order();
        $pendingStatus = (new Status())->setStatus('pending')->setRealStatus('open');
        $paidStatus = (new Status())->setStatus('paid')->setRealStatus('closed');
        $invoice = (new Invoice())->setStatus($pendingStatus);
        $invoice->addOrder(
            (new OrderInvoice())->setOrder($order)->setInvoice($invoice),
        );

        $invoiceRepository = $this->createMock(EntityRepository::class);
        $invoiceRepository->method('find')->with(77)->willReturn($invoice);
        $statusRepository = $this->createMock(EntityRepository::class);
        $statusRepository->method('find')->with(2)->willReturn($paidStatus);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->method('getRepository')
            ->willReturnCallback(static fn(string $className): EntityRepository => match ($className) {
                Invoice::class => $invoiceRepository,
                Status::class => $statusRepository,
            });
        $commercialContextService = $this->createMock(OrderCommercialContextService::class);
        $commercialContextService
            ->expects(self::once())
            ->method('assertChargeAllowed')
            ->with($order, OrderCommercialContextService::CHARGE_MODE_LOCAL);
        $request = Request::create(
            '/invoices/77',
            'PUT',
            [],
            [],
            [],
            [],
            json_encode(['status' => '/statuses/2']) ?: '{}',
        );

        $subscriber = new OrderChargeAuthorizationSubscriber(
            $manager,
            $commercialContextService,
        );
        $subscriber->onKernelController($this->createControllerEvent($request));
    }

    private function createControllerEvent(Request $request): ControllerEvent
    {
        return new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn(): null => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
