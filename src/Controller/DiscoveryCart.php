<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use ControleOnline\Entity\Order;
use Symfony\Component\HttpFoundation\Response;
use ControleOnline\Service\HydratorService;
use Exception;

class DiscoveryCart
{



    public function __construct(
        private HydratorService $hydratorService,
        private EntityManagerInterface $manager,
    ) {}


    public function __invoke(Request $request): JsonResponse
    {
        try {

            $order = $this->manager->getRepository(Order::class)->find(59628);

            return new JsonResponse($this->hydratorService->data($order, 'order_details:read'), Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
