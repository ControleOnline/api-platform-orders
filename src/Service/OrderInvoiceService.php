<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\PaymentType;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
 AS Security;

class OrderInvoiceService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private StatusService $statusService,
        private ?InvoiceService $invoiceService = null,
    ) {}

    public function postPersist(OrderInvoice $OrderInvoice) {}

    public function createFromPayload(array $payload): OrderInvoice
    {
        $invoiceData = $payload['invoice'] ?? null;
        $order = $this->findOrderReference($payload['order'] ?? null);
        if (!$order instanceof Order) {
            throw new \InvalidArgumentException('Order reference is required');
        }

        $invoice = $this->resolveInvoiceReference($invoiceData);
        $existingOrderInvoice = $this->findExistingOrderInvoice($order, $invoice);
        if ($existingOrderInvoice instanceof OrderInvoice) {
            $this->invoiceService?->payOrder($order);
            return $existingOrderInvoice;
        }

        $orderInvoice = new OrderInvoice();
        $orderInvoice->setOrder($order);
        $orderInvoice->setRealPrice($payload['realPrice'] ?? $invoice->getPrice() ?? 0);
        $orderInvoice->setInvoice($invoice);

        $this->manager->persist($orderInvoice);
        $this->manager->flush();
        $this->invoiceService?->payOrder($order);

        return $orderInvoice;
    }

    public function createFromContent(?string $content): OrderInvoice
    {
        return $this->createFromPayload($this->decodePayload($content));
    }

    private function findPeopleReference(mixed $reference): ?People
    {
        return $this->manager->getRepository(People::class)->find(
            $this->normalizeReferenceId($reference)
        );
    }

    private function findWalletReference(mixed $reference): ?Wallet
    {
        return $this->manager->getRepository(Wallet::class)->find(
            $this->normalizeReferenceId($reference)
        );
    }

    private function findPaymentTypeReference(mixed $reference): ?PaymentType
    {
        return $this->manager->getRepository(PaymentType::class)->find(
            $this->normalizeReferenceId($reference)
        );
    }

    private function findOrderReference(mixed $reference): ?Order
    {
        return $this->manager->getRepository(Order::class)->find(
            $this->normalizeReferenceId($reference)
        );
    }

    private function findInvoiceReference(mixed $reference): ?Invoice
    {
        return $this->manager->getRepository(Invoice::class)->find(
            $this->normalizeReferenceId($reference)
        );
    }

    private function findExistingOrderInvoice(Order $order, Invoice $invoice): ?OrderInvoice
    {
        return $this->manager->getRepository(OrderInvoice::class)->findOneBy([
            'invoice' => $invoice,
            'order' => $order,
        ]);
    }

    private function resolveInvoiceReference(mixed $invoiceData): Invoice
    {
        if (is_array($invoiceData)) {
            $invoice = new Invoice();
            $invoice->setDueDate(new \DateTime($invoiceData['dueDate']));
            $invoice->setPayer($this->findPeopleReference($invoiceData['payer'] ?? null));
            $invoice->setReceiver($this->findPeopleReference($invoiceData['receiver'] ?? null));
            $invoice->setStatus($this->statusService->discoveryStatus('closed', 'paid', 'invoice'));
            $invoice->setDestinationWallet($this->findWalletReference($invoiceData['destinationWallet'] ?? null));
            $invoice->setPaymentType($this->findPaymentTypeReference($invoiceData['paymentType'] ?? null));
            $invoice->setPrice($invoiceData['price'] ?? 0);
            $this->manager->persist($invoice);
            return $invoice;
        }

        $invoice = $this->findInvoiceReference($invoiceData);
        if ($invoice instanceof Invoice) {
            return $invoice;
        }

        throw new \InvalidArgumentException('Invoice data is required');
    }

    private function normalizeReferenceId(mixed $reference): int
    {
        return (int) filter_var((string) $reference, FILTER_SANITIZE_NUMBER_INT);
    }

    private function decodePayload(?string $content): array
    {
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
