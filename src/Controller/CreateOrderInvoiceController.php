<?php

namespace ControleOnline\Controller;

use ControleOnline\Service\StatusService;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;

class CreateOrderInvoiceController
{

    public function __construct(private StatusService $statusService) {}

    public function __invoke(
        Request $request,
        EntityManagerInterface $em,
        SerializerInterface $serializer
    ) {
        $data = json_decode($request->getContent(), true);

        $invoiceData = $data['invoice'] ?? null;
        if (!$invoiceData) {
            return new JsonResponse(['error' => 'Invoice data is required'], 400);
        }


        $invoice = new Invoice();
        $invoice->setDueDate(new \DateTime($invoiceData['dueDate']));
        $invoice->setPayer($em->getRepository('ControleOnline\Entity\People')->find(filter_var($invoiceData['payer'], FILTER_SANITIZE_NUMBER_INT)));
        $invoice->setReceiver($em->getRepository('ControleOnline\Entity\People')->find(filter_var($invoiceData['receiver'], FILTER_SANITIZE_NUMBER_INT)));
        $invoice->setStatus($this->statusService->discoveryStatus('closed', 'paid', 'invoice'));
        $invoice->setDestinationWallet($em->getRepository(Wallet::class)->find(filter_var($invoiceData['destinationWallet'], FILTER_SANITIZE_NUMBER_INT)));
        $invoice->setPaymentType($em->getRepository('ControleOnline\Entity\PaymentType')->find(filter_var($invoiceData['paymentType'], FILTER_SANITIZE_NUMBER_INT)));
        $invoice->setPrice($invoiceData['price'] ?? 0);
        $em->persist($invoice);

        $orderInvoice = new OrderInvoice();
        $orderInvoice->setOrder($em->getRepository('ControleOnline\Entity\Order')->find(filter_var($data['order'], FILTER_SANITIZE_NUMBER_INT)));
        $orderInvoice->setRealPrice($data['realPrice'] ?? 0);
        $orderInvoice->setInvoice($invoice);

        $em->persist($orderInvoice);
        $em->flush();


        return new JsonResponse([
            'id' => $orderInvoice->getId(),
            'invoice' => $invoice->getId(),
            'realPrice' => $orderInvoice->getRealPrice()
        ], 201);
    }
}
