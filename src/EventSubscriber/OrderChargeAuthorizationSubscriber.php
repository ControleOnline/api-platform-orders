<?php

namespace ControleOnline\EventSubscriber;

use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\Status;
use ControleOnline\Service\OrderCommercialContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

class OrderChargeAuthorizationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $manager,
        private OrderCommercialContextService $commercialContextService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if ($this->isInvoiceClosingRequest($request)) {
            $this->assertInvoiceClosingAllowed($request);
            return;
        }

        if (
            strtoupper($request->getMethod()) !== 'POST'
            || rtrim($request->getPathInfo(), '/') !== '/invoices'
        ) {
            return;
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload) || !array_key_exists('order', $payload)) {
            return;
        }

        $orderId = $this->normalizeReferenceId($payload['order']);
        $order = $orderId > 0
            ? $this->manager->getRepository(Order::class)->find($orderId)
            : null;
        if (!$order instanceof Order) {
            throw new BadRequestHttpException('Pedido informado para cobranca nao foi encontrado.');
        }

        // The generic invoice route has no authoritative remote-gateway proof.
        // Client input therefore cannot promote it to remote or external charging.
        $this->commercialContextService->assertChargeAllowed(
            $order,
            OrderCommercialContextService::CHARGE_MODE_LOCAL,
        );
    }

    private function isInvoiceClosingRequest(Request $request): bool
    {
        return in_array(strtoupper($request->getMethod()), ['PUT', 'PATCH'], true)
            && preg_match('#^/invoices/\d+/?$#', $request->getPathInfo()) === 1;
    }

    private function assertInvoiceClosingAllowed(Request $request): void
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload) || !array_key_exists('status', $payload)) {
            return;
        }

        $invoiceId = $this->normalizeReferenceId($request->getPathInfo());
        $statusId = $this->normalizeReferenceId($payload['status']);
        $invoice = $this->manager->getRepository(Invoice::class)->find($invoiceId);
        $requestedStatus = $this->manager->getRepository(Status::class)->find($statusId);
        if (!$invoice instanceof Invoice || !$requestedStatus instanceof Status) {
            throw new BadRequestHttpException('Invoice ou status informado nao foi encontrado.');
        }

        $currentRealStatus = strtolower(trim((string) $invoice->getStatus()?->getRealStatus()));
        $requestedRealStatus = strtolower(trim((string) $requestedStatus->getRealStatus()));
        if ($requestedRealStatus !== 'closed' || $currentRealStatus === 'closed') {
            return;
        }

        foreach ($invoice->getOrder() as $orderInvoice) {
            if (!$orderInvoice instanceof OrderInvoice) {
                continue;
            }

            $order = $orderInvoice->getOrder();
            if ($order instanceof Order) {
                $this->commercialContextService->assertChargeAllowed(
                    $order,
                    OrderCommercialContextService::CHARGE_MODE_LOCAL,
                );
            }
        }
    }

    private function normalizeReferenceId(mixed $reference): int
    {
        if (is_array($reference)) {
            $reference = $reference['@id'] ?? $reference['id'] ?? '';
        }

        $normalized = preg_replace('/\D+/', '', (string) $reference);

        return (int) ($normalized ?: 0);
    }
}
