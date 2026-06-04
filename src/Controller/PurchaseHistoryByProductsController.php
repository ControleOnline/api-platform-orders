<?php

namespace ControleOnline\Controller;

use ControleOnline\Repository\OrderRepository;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

#[SecurityAttribute("is_granted('ROLE_HUMAN')")]
class PurchaseHistoryByProductsController
{
    public function __construct(private readonly OrderRepository $orderRepository)
    {
    }

    #[Route('/orders/purchase-history-by-products', name: 'orders_purchase_history_by_products', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (int) ($request->query->get('company') ?? $request->query->get('companyId') ?? 0);
        $productIds = $this->resolveIdList(
            $request->query->get('productIds') ?? $request->query->get('products') ?? [],
        );
        $providerIds = $this->resolveIdList(
            $request->query->get('providerIds') ?? $request->query->get('providers') ?? [],
        );

        if ($companyId <= 0 || $productIds === []) {
            return new JsonResponse([]);
        }

        $rows = $this->orderRepository->findLatestPurchaseHistoryByProductIds(
            $companyId,
            $productIds,
            $providerIds,
        );

        $seen = [];
        $payload = [];

        foreach ($rows as $row) {
            $productId = (int) ($row['productId'] ?? 0);
            if ($productId <= 0 || isset($seen[$productId])) {
                continue;
            }

            $seen[$productId] = true;

            $providerName = trim((string) ($row['providerName'] ?? ''));
            $providerAlias = trim((string) ($row['providerAlias'] ?? ''));

            $payload[] = [
                'productId' => $productId,
                'orderId' => (int) ($row['orderId'] ?? 0),
                'orderDate' => $this->formatDate($row['orderDate'] ?? null),
                'alterDate' => $this->formatDate($row['alterDate'] ?? null),
                'supplierId' => isset($row['providerId']) ? (int) $row['providerId'] : null,
                'supplierLabel' => $providerName !== '' ? $providerName : ($providerAlias !== '' ? $providerAlias : 'Fornecedor não vinculado'),
                'quantity' => (float) ($row['quantity'] ?? 0),
                'unitPrice' => (float) ($row['unitPrice'] ?? 0),
                'totalPrice' => (float) ($row['totalPrice'] ?? 0),
            ];

            if (count($payload) >= count($productIds)) {
                break;
            }
        }

        return new JsonResponse($payload);
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function resolveIdList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $candidates = is_array($value)
            ? $value
            : preg_split('/[,\s]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);

        $ids = [];

        foreach ((array) $candidates as $candidate) {
            $id = (int) preg_replace('/\D+/', '', (string) $candidate);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = trim((string) $value);
        return $text !== '' ? $text : null;
    }
}
