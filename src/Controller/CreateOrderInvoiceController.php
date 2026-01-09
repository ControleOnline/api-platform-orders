<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;

class CreateOrderInvoiceController
{
    public function __invoke(Request $request, EntityManagerInterface $em, SerializerInterface $serializer)
    {
        $data = json_decode($request->getContent(), true);

        $invoiceData = $data['invoice'] ?? null;
        if (!$invoiceData) {
            return new JsonResponse(['error' => 'Invoice data is required'], 400);
        }

        $invoice = new Invoice();
        $invoice->setDueDate(new \DateTime($invoiceData['dueDate']));
        $invoice->setPayer(filter_var($invoiceData['payer'], FILTER_SANITIZE_NUMBER_INT));
        $invoice->setReceiver(filter_var($invoiceData['receiver'], FILTER_SANITIZE_NUMBER_INT));
        $invoice->setStatus(filter_var($invoiceData['status'], FILTER_SANITIZE_NUMBER_INT));
        $invoice->setDestinationWallet(filter_var($invoiceData['destinationWallet'], FILTER_SANITIZE_NUMBER_INT));
        $invoice->setPaymentType(filter_var($invoiceData['paymentType'], FILTER_SANITIZE_NUMBER_INT));
        $invoice->setPrice($invoiceData['price'] ?? 0);
        $em->persist($invoice);

        $orderInvoice = new OrderInvoice();
        $orderInvoice->setOrder(filter_var($data['order'], FILTER_SANITIZE_NUMBER_INT));
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
