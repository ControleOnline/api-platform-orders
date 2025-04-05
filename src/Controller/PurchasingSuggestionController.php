<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Doctrine\ORM\EntityManagerInterface;
use ControleOnline\Entity\People;
use ControleOnline\Service\OrderService;

class PurchasingSuggestionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderService $orderService
    ) {}

    /**
     * @Route("/orders/purchasing-suggestion", name="purchasing_suggestion", methods={"GET"})
     * @Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')")
     */
    public function getPurchasingSuggestion(Request $request): JsonResponse
    {
        $company =  $this->entityManager->getRepository(People::class)->find($request->get('company'));
        $data = $this->orderService->getPurchasingSuggestion($company);
        return new JsonResponse($data);
    }
}
