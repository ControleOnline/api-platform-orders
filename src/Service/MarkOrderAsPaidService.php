<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\PaymentType;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Dedicated mark-as-paid flow for app-community#797.
 *
 * Does not mutate order_products (avoids cart-only guard) and does not use
 * the generic POST /invoices route (avoids PDV-only local charge capability).
 * Authorization is company/tenant access for Manager operators.
 */
class MarkOrderAsPaidService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private PeopleService $peopleService,
        private StatusService $statusService,
        private ?InvoiceService $invoiceService = null,
    ) {}

    /**
     * @param array{
     *   paymentType?: mixed,
     *   destinationWallet?: mixed,
     *   product?: mixed,
     *   price?: mixed,
     *   idempotencyKey?: string
     * } $payload
     */
    public function markAsPaid(Order $order, array $payload, People $actor): array
    {
        $this->assertActorCanAccessOrder($order, $actor);
        $this->assertOrderEligible($order);

        $receiver = $order->getProvider();
        if (!$receiver instanceof People) {
            throw new BadRequestHttpException("Pedido sem empresa provedora.");
        }

        $remaining = $this->resolveRemainingBalance($order);
        if ($remaining <= 0.00001) {
            return $this->envelope($order, null, true, 'Pedido ja esta quitado.');
        }

        $paymentType = $this->requirePaymentType($payload['paymentType'] ?? null, $receiver);
        $wallet = $this->resolveWallet($payload['destinationWallet'] ?? null, $receiver);
        $product = $this->resolveProduct($payload['product'] ?? null, $receiver);

        // The client cannot choose the invoice amount. The server owns the
        // outstanding balance and always settles that authoritative amount.
        $chargeAmount = $remaining;

        $paidInvoiceStatus = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        if ($paidInvoiceStatus === null) {
            throw new BadRequestHttpException('Status pago da invoice nao foi encontrado.');
        }

        $payer = $order->getClient() ?: $order->getPayer();

        $description = sprintf(
            'Marcar como pago · pedido #%s%s',
            (string) $order->getId(),
            $product instanceof Product
                ? ' · ' . trim((string) ($product->getProduct() ?: $product->getName() ?: 'produto'))
                : ''
        );

        $connection = $this->manager->getConnection();
        $connection->beginTransaction();

        try {
            $invoice = new Invoice();
            $invoice->setDueDate(new \DateTime('today'));
            $invoice->setPayer($payer instanceof People ? $payer : null);
            $invoice->setReceiver($receiver);
            $invoice->setStatus($paidInvoiceStatus);
            $invoice->setDestinationWallet($wallet);
            $invoice->setPaymentType($paymentType);
            $invoice->setPrice($chargeAmount);
            $invoice->setDescription(mb_substr($description, 0, 250));
            $this->manager->persist($invoice);

            $orderInvoice = new OrderInvoice();
            $orderInvoice->setOrder($order);
            $orderInvoice->setInvoice($invoice);
            $orderInvoice->setRealPrice($chargeAmount);
            $this->manager->persist($orderInvoice);
            // Keep inverse collection in sync so settle sees the new paid invoice
            // without relying solely on a DB re-fetch (app-community#837).
            if (method_exists($order, 'addInvoice')) {
                $order->addInvoice($orderInvoice);
            }

            $this->manager->flush();

            // Settle order status from paid invoices (same path as normal payments).
            if ($this->invoiceService !== null) {
                $this->invoiceService->payOrder($order);
            } else {
                $this->fallbackSettleOrder($order, $chargeAmount, $remaining);
            }

            // Safety net: invoice may be paid while order status stayed open when the
            // in-memory invoice collection was stale or payOrder did not transition.
            $this->ensureOrderMarkedPaid($order, $chargeAmount, $remaining);

            $this->manager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }

        $this->manager->refresh($order);

        return $this->envelope($order, $orderInvoice ?? null, false, 'ok');
    }

    private function assertActorCanAccessOrder(Order $order, People $actor): void
    {
        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            throw new AccessDeniedHttpException('Pedido sem empresa provedora.');
        }

        if ((int) $actor->getId() === (int) $provider->getId()) {
            return;
        }

        if (method_exists($this->peopleService, 'canAccessCompany')
            && $this->peopleService->canAccessCompany($provider, $actor)
        ) {
            return;
        }

        // Fallback: same companies list used elsewhere in the module.
        $providerId = (int) $provider->getId();
        foreach ($this->peopleService->getMyCompanies() as $company) {
            $companyId = $company instanceof People ? (int) $company->getId() : (int) $company;
            if ($providerId > 0 && $companyId === $providerId) {
                return;
            }
        }

        throw new AccessDeniedHttpException('Acesso negado a este pedido.');
    }

    private function assertOrderEligible(Order $order): void
    {
        $real = strtolower(trim((string) $order->getStatus()?->getRealStatus()));
        if (in_array($real, ['canceled', 'cancelled'], true)) {
            throw new BadRequestHttpException('Pedido cancelado nao pode ser marcado como pago.');
        }
    }

    private function resolveRemainingBalance(Order $order): float
    {
        $price = (float) ($order->getPrice() ?? 0);
        $paid = 0.0;

        foreach ($order->getInvoice() as $orderInvoice) {
            if (!$orderInvoice instanceof OrderInvoice) {
                continue;
            }
            $invoice = $orderInvoice->getInvoice();
            if (!$invoice instanceof Invoice) {
                continue;
            }
            $real = strtolower(trim((string) $invoice->getStatus()?->getRealStatus()));
            if ($real !== 'closed' && $real !== 'paid') {
                continue;
            }
            $paid += (float) ($orderInvoice->getRealPrice() ?? $invoice->getPrice() ?? 0);
        }

        return max(0.0, round($price - $paid, 2));
    }

    private function requirePaymentType(mixed $reference, People $provider): PaymentType
    {
        $id = $this->normalizeReferenceId($reference);
        $paymentType = $id > 0
            ? $this->manager->getRepository(PaymentType::class)->find($id)
            : null;
        if (!$paymentType instanceof PaymentType) {
            throw new BadRequestHttpException('Forma de pagamento invalida.');
        }

        $this->assertSameCompany($paymentType->getPeople(), $provider, 'Forma de pagamento invalida.');

        return $paymentType;
    }

    private function resolveWallet(mixed $reference, People $provider): ?Wallet
    {
        $id = $this->normalizeReferenceId($reference);
        if ($id <= 0) {
            return null;
        }
        $wallet = $this->manager->getRepository(Wallet::class)->find($id);

        if (!$wallet instanceof Wallet) {
            throw new BadRequestHttpException('Carteira de destino invalida.');
        }

        $this->assertSameCompany($wallet->getPeople(), $provider, 'Carteira de destino invalida.');

        return $wallet;
    }

    private function resolveProduct(mixed $reference, People $provider): ?Product
    {
        $id = $this->normalizeReferenceId($reference);
        if ($id <= 0) {
            return null;
        }
        $product = $this->manager->getRepository(Product::class)->find($id);

        if (!$product instanceof Product) {
            throw new BadRequestHttpException('Produto invalido.');
        }

        $this->assertSameCompany($product->getCompany(), $provider, 'Produto invalido.');

        return $product;
    }

    private function assertSameCompany(?People $candidate, People $provider, string $message): void
    {
        if (!$candidate instanceof People) {
            throw new BadRequestHttpException($message);
        }

        $candidateId = (int) $candidate->getId();
        $providerId = (int) $provider->getId();
        if ($candidate !== $provider && ($candidateId <= 0 || $providerId <= 0 || $candidateId !== $providerId)) {
            throw new AccessDeniedHttpException($message);
        }
    }

    private function fallbackSettleOrder(Order $order, float $justPaid, float $remainingBeforeCharge): void
    {
        $remaining = $this->resolveRemainingBalance($order);
        // After flush, collection may still omit the invoice just linked via owning side only.
        // If the charge covered the pre-charge balance, treat as fully settled (#837).
        if ($remaining > 0.009 && ($justPaid + 0.009) < $remainingBeforeCharge) {
            return;
        }

        $this->applyPaidOrderStatus($order);
    }

    /**
     * Force order status to paid/closed when the charge covers the outstanding balance.
     */
    private function ensureOrderMarkedPaid(Order $order, float $justPaid, float $remainingBeforeCharge): void
    {
        $real = strtolower(trim((string) $order->getStatus()?->getRealStatus()));
        if (in_array($real, ['closed', 'paid'], true)) {
            return;
        }

        $remaining = $this->resolveRemainingBalance($order);
        $covered = $remaining <= 0.009 || ($justPaid + 0.009) >= $remainingBeforeCharge;
        if (!$covered) {
            return;
        }

        $this->applyPaidOrderStatus($order);
    }

    private function applyPaidOrderStatus(Order $order): void
    {
        // Lave-Go / Controle Online: paid = real_status open + status paid (#909).
        // Never fall back to closed/closed after successful payment.
        $orderStatus = $this->statusService->discoveryStatus('open', 'paid', 'order')
            ?: $this->statusService->discoveryStatus('closed', 'paid', 'order');
        if ($orderStatus === null) {
            throw new BadRequestHttpException(
                'Status de pedido pago (open/paid) nao encontrado no catalogo do tenant.'
            );
        }

        $order->setStatus($orderStatus);
        $this->manager->persist($order);
    }

    private function normalizeReferenceId(mixed $reference): int
    {
        if (is_array($reference)) {
            $reference = $reference['@id'] ?? $reference['id'] ?? '';
        }

        return (int) preg_replace('/\D+/', '', (string) $reference);
    }

    private function envelope(
        Order $order,
        ?OrderInvoice $orderInvoice,
        bool $alreadyPaid,
        string $message,
    ): array {
        $status = $order->getStatus();

        return [
            'outcome' => 'success',
            'alreadyPaid' => $alreadyPaid,
            'message' => $message,
            'order' => [
                'id' => $order->getId(),
                'status' => $status?->getStatus(),
                'realStatus' => $status?->getRealStatus(),
                'price' => $order->getPrice(),
                'balance' => $this->resolveRemainingBalance($order),
            ],
            'invoice' => $orderInvoice instanceof OrderInvoice ? [
                'id' => $orderInvoice->getInvoice()?->getId(),
                'orderInvoiceId' => $orderInvoice->getId(),
                'realPrice' => $orderInvoice->getRealPrice(),
                'status' => $orderInvoice->getInvoice()?->getStatus()?->getStatus(),
                'realStatus' => $orderInvoice->getInvoice()?->getStatus()?->getRealStatus(),
            ] : null,
        ];
    }
}
