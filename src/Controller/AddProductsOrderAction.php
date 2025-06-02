<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\Product;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\OrderService;
use Exception;
use Symfony\Component\HttpFoundation\Response;

class AddProductsOrderAction
{


    public function __construct(
        private EntityManagerInterface $entityManager,
        private HydratorService $hydratorService,
        private OrderProductService $orderProductService,
        private OrderService $orderService

    ) {}

    public function __invoke(Request $request, int $id): JsonResponse
    {
        try {
            $order = $this->entityManager->getRepository(Order::class)->find($id);
            if (!$order)
                return new JsonResponse(['error' => 'Order not found'], 404);

            $data = json_decode($request->getContent(), true);

            foreach ($data as $p) {
                $product = $this->entityManager->getRepository(Product::class)->find($p['product']);
                $quantity = $p['quantity'];
                $price = $product->getPrice();
                $this->orderProductService->addOrderProduct($order, $product, $quantity, $price);
            }
            $this->entityManager->flush();
            $this->orderService->calculateOrderPrice($order);
            $this->entityManager->refresh($order);

            return new JsonResponse($this->hydratorService->item(Order::class, $order->getId(), "order:write"), Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
