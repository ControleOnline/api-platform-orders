<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Service\InvoiceService;
use ControleOnline\Service\OrderInvoiceService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class OrderInvoiceServiceTest extends TestCase
{
    public function testCreateFromPayloadAcceptsExistingInvoiceReference(): void
    {
        $order = new Order();
        $invoice = new Invoice();
        $invoice->setPrice(42.5);

        $orderRepository = $this->createMock(ObjectRepository::class);
        $orderRepository
            ->expects(self::once())
            ->method('find')
            ->with(10)
            ->willReturn($order);

        $invoiceRepository = $this->createMock(ObjectRepository::class);
        $invoiceRepository
            ->expects(self::once())
            ->method('find')
            ->with(20)
            ->willReturn($invoice);

        $orderInvoiceRepository = $this->createMock(ObjectRepository::class);
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
                    default => $this->createMock(ObjectRepository::class),
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

        $service = new OrderInvoiceService(
            $entityManager,
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(StatusService::class),
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
}
