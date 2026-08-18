<?php

namespace ControleOnline\Orders\Tests\EventSubscriber;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use ControleOnline\EventSubscriber\OrderChargeAuthorizationSubscriber;
use ControleOnline\Service\DeviceService;
use ControleOnline\Service\OrderCommercialContextService;
use ControleOnline\Service\PeopleService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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

    public function testDirectInvoiceRouteRejectsManagerDisguisedByClientMetadata(): void
    {
        $provider = $this->createPeople(45);
        $order = (new Order())->setProvider($provider);
        $device = (new Device())
            ->setDevice('manager-device')
            ->setMetadata(['appType' => 'POS']);
        $managerConfig = (new DeviceConfig())
            ->setPeople($provider)
            ->setDevice($device)
            ->setType('MANAGER')
            ->setConfigs([OrderCommercialContextService::CHARGE_CONFIG_KEY => true]);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->method('find')->with(45)->willReturn($order);
        $deviceRepository = $this->createMock(EntityRepository::class);
        $deviceRepository
            ->method('findOneBy')
            ->with(['device' => 'manager-device'])
            ->willReturn($device);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->method('getRepository')
            ->willReturnCallback(static fn(string $className): EntityRepository => match ($className) {
                Order::class => $orderRepository,
                Device::class => $deviceRepository,
            });

        $peopleService = $this->createMock(PeopleService::class);
        $peopleService->method('getMyCompanies')->willReturn([$provider]);
        $deviceService = $this->createMock(DeviceService::class);
        $deviceService
            ->method('findDeviceConfigs')
            ->with($device, $provider)
            ->willReturn([$managerConfig]);

        $request = Request::create(
            '/invoices',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['order' => '/orders/45']) ?: '{}',
        );
        $request->headers->set('DEVICE', 'manager-device');
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $commercialContextService = new OrderCommercialContextService(
            $manager,
            $peopleService,
            $deviceService,
            $requestStack,
        );
        $subscriber = new OrderChargeAuthorizationSubscriber(
            $manager,
            $commercialContextService,
        );

        $this->expectException(AccessDeniedHttpException::class);
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

    public function testDirectOrderRoutesCannotSetOrWeakenTemporalPolicy(): void
    {
        foreach (
            [
                ['/orders', 'POST', 'payBeforeProduction'],
                ['/orders/77', 'PUT', 'pay_before_production'],
                ['/orders/77', 'PATCH', 'payBeforeProduction'],
            ] as [$path, $method, $field]
        ) {
            $subscriber = new OrderChargeAuthorizationSubscriber(
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(OrderCommercialContextService::class),
            );
            $request = Request::create(
                $path,
                $method,
                [],
                [],
                [],
                [],
                json_encode([$field => false]) ?: '{}',
            );

            try {
                $subscriber->onKernelController($this->createControllerEvent($request));
                self::fail(sprintf('%s %s should reject client-controlled policy.', $method, $path));
            } catch (BadRequestHttpException $exception) {
                self::assertStringContainsString('politica server-side', $exception->getMessage());
            }
        }
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

    private function createPeople(int $id): People
    {
        $people = new People();
        $property = new \ReflectionProperty(People::class, 'id');
        $property->setAccessible(true);
        $property->setValue($people, $id);

        return $people;
    }
}
