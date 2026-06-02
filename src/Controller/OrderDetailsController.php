<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Order;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OrderDetailsController
{
    public function __construct(
        private readonly HydratorService $hydratorService,
        private readonly OrderService $orderService,
    ) {
    }

    public function __invoke(int $id): JsonResponse
    {
        try {
            $order = $this->orderService->findOrderById($id);
            if (!$order instanceof Order) {
                return new JsonResponse(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
            }

            return new JsonResponse(
                $this->hydratorService->item(
                    Order::class,
                    $id,
                    'order_details:read',
                ),
                Response::HTTP_OK,
            );
        } catch (\Throwable $exception) {
            return new JsonResponse(
                $this->hydratorService->error(
                    new Exception(
                        $exception->getMessage(),
                        (int) $exception->getCode(),
                        $exception,
                    ),
                ),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
