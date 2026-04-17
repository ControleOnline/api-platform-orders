<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use ControleOnline\Entity\Order;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\OrderService;
use Exception;
use Symfony\Component\HttpFoundation\Response;

class AddProductsOrderAction
{


    public function __construct(
        private HydratorService $hydratorService,
        private OrderProductService $orderProductService,
        private OrderService $orderService

    ) {}

    public function __invoke(Request $request, int $id): JsonResponse
    {
        try {
            $order = $this->orderService->findOrderById($id);
            if (!$order)
                return new JsonResponse(['error' => 'Order not found'], 404);

            $this->orderProductService->addProductsToOrder(
                $order,
                json_decode($request->getContent(), true) ?? []
            );

            return new JsonResponse($this->hydratorService->item(Order::class, $order->getId(), "order:write"), Response::HTTP_OK);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
