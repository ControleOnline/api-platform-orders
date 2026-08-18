<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\People;
use ControleOnline\Service\InvoiceService;
use ControleOnline\Service\OrderCommercialContextService;
use ControleOnline\Service\OrderInvoiceService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[AllowMockObjectsWithoutExpectations]
class OrderInvoiceServiceTest extends TestCase
{
    public function testCreateFromPayloadAcceptsExistingInvoiceReference(): void
    {
        $order = new Order();
        $invoice = new Invoice();
        $invoice->setPrice(42.5);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->expects(self::once())
            ->method('find')
            ->with(10)
            ->willReturn($order);

        $invoiceRepository = $this->createMock(EntityRepository::class);
        $invoiceRepository
            ->expects(self::once())
            ->method('find')
            ->with(20)
            ->willReturn($invoice);

        $orderInvoiceRepository = $this->createMock(EntityRepository::class);
        $orderInvoiceRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with([
                'invoice' => $invoice,
                'order' => $order,
            ])
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(function (string $className) use (
                $invoiceRepository,
                $orderInvoiceRepository,
                $orderRepository
            ) {
                return match ($className) {
                    Order::class => $orderRepository,
                    Invoice::class => $invoiceRepository,
                    OrderInvoice::class => $orderInvoiceRepository,
                    default => $this->createMock(EntityRepository::class),
                };
            });

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($invoice, $order): bool {
                return $entity instanceof OrderInvoice
                    && $entity->getOrder() === $order
                    && $entity->getInvoice() === $invoice
                    && $entity->getRealPrice() === 15.75;
            }));

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService
            ->expects(self::once())
            ->method('payOrder')
            ->with($order);

        $commercialContextService = $this->createMock(OrderCommercialContextService::class);
        $commercialContextService
            ->expects(self::once())
            ->method('assertChargeAllowed')
            ->with($order);

        $service = new OrderInvoiceService(
            $entityManager,
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(StatusService::class),
            $commercialContextService,
            $invoiceService,
        );

        $createdOrderInvoice = $service->createFromPayload([
            'order' => '/orders/10',
            'invoice' => '/invoices/20',
            'realPrice' => 15.75,
        ]);

        self::assertInstanceOf(OrderInvoice::class, $createdOrderInvoice);
        self::assertSame($order, $createdOrderInvoice->getOrder());
        self::assertSame($invoice, $createdOrderInvoice->getInvoice());
        self::assertSame(15.75, $createdOrderInvoice->getRealPrice());
    }

    public function testExistingSameTenantInvoiceRequiresChargeCapabilityForConsolidation(): void
    {
        $provider = $this->createPeople(50);
        $sourceOrder = (new Order())->setProvider($provider);
        $targetOrder = (new Order())->setProvider($provider);
        $invoice = (new Invoice())->setPrice(25);
        $invoice->addOrder(
            (new OrderInvoice())
                ->setOrder($sourceOrder)
                ->setInvoice($invoice)
                ->setRealPrice(25),
        );

        [$entityManager, $orderInvoiceRepository] = $this->buildReferenceRepositories(
            $targetOrder,
            $invoice,
        );
        $orderInvoiceRepository->method('findOneBy')->willReturn(null);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(OrderInvoice::class));
        $entityManager->expects(self::once())->method('flush');
        $commercialContextService = $this->createMock(OrderCommercialContextService::class);
        $commercialContextService
            ->expects(self::once())
            ->method('assertChargeAllowed')
            ->with($targetOrder);
        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->expects(self::once())->method('payOrder')->with($targetOrder);
        $service = new OrderInvoiceService(
            $entityManager,
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(StatusService::class),
            $commercialContextService,
            $invoiceService,
        );

        $service->createFromPayload([
            'order' => '/orders/10',
            'invoice' => '/invoices/20',
            'realPrice' => 25,
        ]);
    }

    public function testDirectPublicConsolidationCannotLinkOrPayWithoutCapability(): void
    {
        $provider = $this->createPeople(53);
        $sourceOrder = (new Order())->setProvider($provider);
        $targetOrder = (new Order())->setProvider($provider);
        $invoice = (new Invoice())->setPrice(25);
        $invoice->addOrder(
            (new OrderInvoice())
                ->setOrder($sourceOrder)
                ->setInvoice($invoice)
                ->setRealPrice(25),
        );

        [$entityManager] = $this->buildReferenceRepositories($targetOrder, $invoice);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $commercialContextService = $this->createMock(OrderCommercialContextService::class);
        $commercialContextService
            ->expects(self::once())
            ->method('assertChargeAllowed')
            ->with($targetOrder)
            ->willThrowException(new AccessDeniedHttpException('capacidade financeira ausente'));
        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->expects(self::never())->method('payOrder');
        $service = new OrderInvoiceService(
            $entityManager,
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(StatusService::class),
            $commercialContextService,
            $invoiceService,
        );

        $this->expectException(AccessDeniedHttpException::class);
        $service->createFromPayload([
            'order' => '/orders/10',
            'invoice' => '/invoices/20',
            'realPrice' => 25,
        ]);
    }

    public function testExistingInvoiceCannotBeLinkedAcrossTenants(): void
    {
        $sourceOrder = (new Order())->setProvider($this->createPeople(51));
        $targetOrder = (new Order())->setProvider($this->createPeople(52));
        $invoice = (new Invoice())->setPrice(25);
        $invoice->addOrder(
            (new OrderInvoice())
                ->setOrder($sourceOrder)
                ->setInvoice($invoice)
                ->setRealPrice(25),
        );

        [$entityManager, $orderInvoiceRepository] = $this->buildReferenceRepositories(
            $targetOrder,
            $invoice,
        );
        $orderInvoiceRepository->method('findOneBy')->willReturn(null);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $service = new OrderInvoiceService(
            $entityManager,
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(StatusService::class),
            $this->createMock(OrderCommercialContextService::class),
            $this->createMock(InvoiceService::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice nao pode ser vinculada a Order de outro tenant.');
        $service->createFromPayload([
            'order' => '/orders/10',
            'invoice' => '/invoices/20',
            'realPrice' => 25,
        ]);
    }

    private function buildReferenceRepositories(Order $order, Invoice $invoice): array
    {
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->method('find')->with(10)->willReturn($order);
        $invoiceRepository = $this->createMock(EntityRepository::class);
        $invoiceRepository->method('find')->with(20)->willReturn($invoice);
        $orderInvoiceRepository = $this->createMock(EntityRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(static fn(string $className): EntityRepository => match ($className) {
                Order::class => $orderRepository,
                Invoice::class => $invoiceRepository,
                OrderInvoice::class => $orderInvoiceRepository,
            });

        return [$entityManager, $orderInvoiceRepository];
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
