<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\OrderPrintService;
use ControleOnline\Service\OrderService;

class PurchasingSuggestionAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderService $orderService
    ) {}

    /**
     * @Route("/orders/purchasing-suggestion", name="invoice_inflow", methods={"GET"})
     * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')")
     */
    public function getPurchasingSuggestion(Request $request): JsonResponse
    {
        $company =  $this->entityManager->getRepository(People::class)->find($request->get('company'));
        $data = $this->orderService->getPurchasingSuggestion($company);
        return new JsonResponse($data);
    }
}
