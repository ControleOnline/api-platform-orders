<?php

namespace ControleOnline\Controller;

use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderDeliveryMapService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

#[SecurityAttribute("is_granted('ROLE_HUMAN')")]
class OrderDeliveryMapController
{
    public function __construct(
        private OrderDeliveryMapService $deliveryMapService,
        private HydratorService $hydratorService,
    ) {
    }

    #[Route('/orders-delivery-map', name: 'orders_delivery_map', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $this->deliveryMapService->buildPayload($request->query->get('provider'));

            return new JsonResponse(
                $this->hydratorService->result([$payload]),
                Response::HTTP_OK,
            );
        } catch (HttpExceptionInterface $exception) {
            return new JsonResponse(
                ['error' => $exception->getMessage()],
                $exception->getStatusCode(),
            );
        } catch (\Throwable $exception) {
            return new JsonResponse(
                $this->hydratorService->error(new \Exception($exception->getMessage())),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
