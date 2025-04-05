<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use ControleOnline\Entity\Order;
use ControleOnline\Service\OrderPrintService;

class PurchasingSuggestionAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderPrintService $print
    ) {}

    /**
     * @Route("/orders/purchasing-suggestion", name="invoice_inflow", methods={"GET"})
     * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')")
     */
    public function __invoke(Request $request, int $id): JsonResponse
    {
        $order = $this->entityManager->getRepository(Order::class)->find($id);
        if (!$order) {
            return new JsonResponse(['error' => 'Order not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $printType = $data['print-type'] ?? 'pos';
        $deviceType = $data['device-type'] ?? 'cielo';

        $printData = $this->print->generatePrintData($order, $printType, $deviceType);

        return new JsonResponse($printData);
    }
}
