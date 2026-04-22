<?php

namespace ControleOnline\Controller;

use ApiPlatform\State\Pagination\PartialPaginatorInterface;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Service\HydratorService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class OrderProductCollectionController
{
    public function __construct(
        private HydratorService $hydratorService
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            // Reaproveita a colecao ja filtrada e autorizada pela API Platform.
            $orderProducts = $request->attributes->get('data', []);

            $totalItems = $orderProducts instanceof PartialPaginatorInterface
                ? $orderProducts->getTotalItems()
                : (is_countable($orderProducts) ? count($orderProducts) : 0);

            return new JsonResponse(
                $this->hydratorService->collectionData(
                    $orderProducts,
                    OrderProduct::class,
                    'order_product:read',
                    [],
                    $totalItems
                )
            );
        } catch (\Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
