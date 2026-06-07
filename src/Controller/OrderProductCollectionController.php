<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Order;
use ControleOnline\Service\HydratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderProductCollectionController
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly HydratorService $hydratorService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $criteria = $this->buildCriteria($request);
            $itemsPerPage = max(1, (int) $request->query->get('itemsPerPage', 50));
            $page = max(1, (int) $request->query->get('page', 1));
            $offset = ($page - 1) * $itemsPerPage;

            $items = $this->manager
                ->getRepository(OrderProduct::class)
                ->findBy($criteria, ['id' => 'ASC'], $itemsPerPage, $offset);

            return new JsonResponse(
                $this->hydratorService->collectionData(
                    $items,
                    OrderProduct::class,
                    'order_product:read',
                    $criteria,
                ),
                Response::HTTP_OK,
            );
        } catch (\Throwable $exception) {
            return new JsonResponse(
                $this->hydratorService->error(
                    new \Exception(
                        $exception->getMessage(),
                        (int) $exception->getCode(),
                        $exception,
                    ),
                ),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    private function buildCriteria(Request $request): array
    {
        $criteria = [];
        $orderId = $this->resolveOrderId($request);

        if (null !== $orderId) {
            $criteria['order'] = $this->manager->getReference(Order::class, $orderId);
        }

        return $criteria;
    }

    private function resolveOrderId(Request $request): ?int
    {
        $query = $request->query->all();

        $candidates = [
            $query['order_id'] ?? null,
            $query['orderId'] ?? null,
            $query['order'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $resolvedId = $this->extractNumericId($candidate);
            if (null !== $resolvedId) {
                return $resolvedId;
            }
        }

        return null;
    }

    private function extractNumericId(mixed $value): ?int
    {
        if (is_array($value)) {
            foreach (['id', '@id', 'value'] as $key) {
                if (!array_key_exists($key, $value)) {
                    continue;
                }

                $resolvedId = $this->extractNumericId($value[$key]);
                if (null !== $resolvedId) {
                    return $resolvedId;
                }
            }

            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value)) {
            $digits = preg_replace('/\D+/', '', $value);
            if ($digits !== '') {
                return (int) $digits;
            }
        }

        return null;
    }
}
