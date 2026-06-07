<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Order;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderProductService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderProductCollectionController
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly HydratorService $hydratorService,
        private readonly OrderProductService $orderProductService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $orderId = $this->resolveOrderId($request);
            $itemsPerPage = max(1, (int) $request->query->get('itemsPerPage', 50));
            $page = max(1, (int) $request->query->get('page', 1));
            $offset = ($page - 1) * $itemsPerPage;

            $totalItems = (int) $this->createFilteredQueryBuilder($orderId)
                ->select('COUNT(DISTINCT orderProduct.id)')
                ->getQuery()
                ->getSingleScalarResult();

            $items = $this->createFilteredQueryBuilder($orderId)
                ->addOrderBy('orderProduct.id', 'ASC')
                ->setMaxResults($itemsPerPage)
                ->setFirstResult($offset)
                ->getQuery()
                ->getResult();

            return new JsonResponse(
                $this->hydratorService->collectionData(
                    $items,
                    OrderProduct::class,
                    'order_product:read',
                    [],
                    $totalItems,
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

    private function createFilteredQueryBuilder(?int $orderId): QueryBuilder
    {
        $queryBuilder = $this->manager
            ->createQueryBuilder()
            ->select('orderProduct')
            ->from(OrderProduct::class, 'orderProduct');

        $this->orderProductService->securityFilter(
            $queryBuilder,
            OrderProduct::class,
            'collection',
            'orderProduct',
        );

        if (null !== $orderId) {
            $queryBuilder
                ->andWhere('orderProduct.order = :orderProductOrder')
                ->setParameter(
                    'orderProductOrder',
                    $this->manager->getReference(Order::class, $orderId),
                );
        }

        return $queryBuilder;
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
