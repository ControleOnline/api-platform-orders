<?php

namespace ControleOnline\Controller;

use ControleOnline\Service\OrderInvoiceService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class CreateOrderInvoiceController
{
    public function __construct(private OrderInvoiceService $orderInvoiceService) {}

    public function __invoke(
        Request $request
    ) {
        try {
            $orderInvoice = $this->orderInvoiceService->createFromContent(
                $request->getContent()
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 400);
        }

        return new JsonResponse([
            'id' => $orderInvoice->getId(),
            'invoice' => $orderInvoice->getInvoice()?->getId(),
            'realPrice' => $orderInvoice->getRealPrice()
        ], 201);
    }
}
