<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Shared private helpers for OrderProductService (api-platform-orders#5 modularization).
 */
trait OrderProductServiceHelpers
{
    private function findProductReference(mixed $reference): ?Product
    {
        return $this->manager->getRepository(Product::class)->find(
            $this->normalizeReferenceId($reference)
        );
    }

    private function normalizeReferenceId(mixed $reference): int
    {
        return (int) preg_replace('/\D+/', '', (string) $reference);
    }

    private function normalizeOrderProductComment(mixed $comment): ?string
    {
        $normalizedComment = trim((string) ($comment ?? ''));

        return $normalizedComment !== '' ? $normalizedComment : null;
    }

    private function decodePayload(?string $content): array
    {
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeOrderProductItems(array $items): array
    {
        if (isset($items['product']) || isset($items['productId'])) {
            return [$items];
        }

        if (array_is_list($items)) {
            return array_values(array_filter(
                $items,
                static fn (mixed $item): bool => is_array($item),
            ));
        }

        if (isset($items['items']) && is_array($items['items'])) {
            return array_values(array_filter(
                $items['items'],
                static fn (mixed $item): bool => is_array($item),
            ));
        }

        return [];
    }

    private function removeExistingOrderProducts(Order $order): void
    {
        $existingOrderProducts = $this->manager->getRepository(OrderProduct::class)->findBy([
            'order' => $order,
        ]);

        foreach ($existingOrderProducts as $existingOrderProduct) {
            if ($existingOrderProduct->getOrderProduct() instanceof OrderProduct) {
                continue;
            }

            $this->removeOrderProductBranch($existingOrderProduct);
        }
    }

    private function guardDirectOrderProductMutation(OrderProduct $orderProduct): void
    {
        $order = $orderProduct->getOrder();
        if (
            !$order instanceof Order
            || !$this->isOrderProductMutationRequest()
        ) {
            return;
        }

        // Importacoes e recalculos internos reutilizam este service, entao a trava so vale para rotas diretas.
        if (!$this->isMutableCartOrder($order)) {
            throw new BadRequestHttpException(
                'Produtos, quantidades e remocoes so podem ser alterados enquanto o pedido estiver em cart.'
            );
        }

        if ($this->orderService->isMarketplaceIntegrationOrder($order)) {
            throw new BadRequestHttpException(
                'Itens de pedidos de integracao nao podem ser editados diretamente.'
            );
        }
    }

    private function isOrderProductMutationRequest(): bool
    {
        if (!$this->request) {
            return false;
        }

        // Somente rotas que mutam item diretamente entram na regra; calculos internos ficam de fora.
        $method = strtoupper((string) $this->request->getMethod());
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $path = (string) $this->request->getPathInfo();

        return (bool) preg_match('#^/order_products(?:/\d+)?$#', $path)
            || (bool) preg_match('#^/orders/\d+/(add-products|replace-products)$#', $path);
    }

    private function isMutableCartOrder(Order $order): bool
    {
        $orderType = strtolower(trim((string) $order->getOrderType()));
        $realStatus = strtolower(trim((string) $order->getStatus()?->getRealStatus()));

        return OrderService::ORDER_TYPE_CART === $orderType
            && !in_array($realStatus, ['closed', 'canceled', 'cancelled'], true);
    }

}
