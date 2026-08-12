<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;

final class OrderProductTreeNormalizer
{
    /**
     * @return list<array{
     *     product: int,
     *     productGroup: int,
     *     quantity: float,
     *     unitQuantity: string,
     *     sub_products: list<array<string, mixed>>
     * }>
     */
    public function normalizeRequestedChildren(array $children, float $parentQuantity): array
    {
        $normalized = [];

        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }

            $productId = $this->normalizeReferenceId($child['product'] ?? null);
            $productGroupId = $this->normalizeReferenceId($child['productGroup'] ?? null);
            $quantity = (float) ($child['quantity'] ?? 0);
            if ($productId <= 0 || $productGroupId <= 0 || $quantity <= 0) {
                continue;
            }

            $normalizedChildren = $this->normalizeRequestedChildren(
                is_array($child['sub_products'] ?? null) ? $child['sub_products'] : [],
                $quantity,
            );
            $node = [
                'product' => $productId,
                'productGroup' => $productGroupId,
                'quantity' => $quantity,
                'unitQuantity' => $this->normalizeQuantity(
                    $quantity / $this->quantityDivisor($parentQuantity),
                ),
                'sub_products' => $normalizedChildren,
            ];
            $configurationKey = $this->requestedConfigurationKey($node);

            if (isset($normalized[$configurationKey])) {
                $normalized[$configurationKey] = $this->mergeEquivalentNodes(
                    $normalized[$configurationKey],
                    $node,
                    $parentQuantity,
                );
                continue;
            }

            $normalized[$configurationKey] = $node;
        }

        $nodes = array_values($normalized);
        usort(
            $nodes,
            fn (array $left, array $right): int => $this->requestedNodeSignature($left)
                <=> $this->requestedNodeSignature($right),
        );

        return $nodes;
    }

    /** @param list<array<string, mixed>> $children */
    public function requestedTreeSignature(array $children): array
    {
        $signature = array_map($this->requestedNodeSignature(...), $children);
        sort($signature);

        return $signature;
    }

    public function requestedNodeSignature(array $node): string
    {
        return json_encode([
            'productGroup' => (int) ($node['productGroup'] ?? 0),
            'product' => (int) ($node['product'] ?? 0),
            'unitQuantity' => (string) ($node['unitQuantity'] ?? '0'),
            'children' => $this->requestedTreeSignature(
                is_array($node['sub_products'] ?? null) ? $node['sub_products'] : [],
            ),
        ], JSON_THROW_ON_ERROR);
    }

    public function persistedTreeSignature(OrderProduct $parent): array
    {
        $signature = [];
        $parentQuantity = (float) $parent->getQuantity();

        foreach ($parent->getOrderProductComponents() as $component) {
            if ($component instanceof OrderProduct) {
                $signature[] = $this->persistedNodeSignature($component, $parentQuantity);
            }
        }

        sort($signature);

        return $signature;
    }

    public function persistedNodeSignature(
        OrderProduct $node,
        float $parentQuantity,
    ): string {
        $product = $node->getProduct();
        $group = $node->getProductGroup();

        return json_encode([
            'productGroup' => $group instanceof ProductGroup ? (int) $group->getId() : 0,
            'product' => $product instanceof Product ? (int) $product->getId() : 0,
            'unitQuantity' => $this->normalizeQuantity(
                (float) $node->getQuantity() / $this->quantityDivisor($parentQuantity),
            ),
            'children' => $this->persistedTreeSignature($node),
        ], JSON_THROW_ON_ERROR);
    }

    private function requestedConfigurationKey(array $node): string
    {
        return json_encode([
            'productGroup' => $node['productGroup'],
            'product' => $node['product'],
            'children' => $this->requestedTreeSignature($node['sub_products']),
        ], JSON_THROW_ON_ERROR);
    }

    private function mergeEquivalentNodes(
        array $left,
        array $right,
        float $parentQuantity,
    ): array {
        $leftQuantity = (float) $left['quantity'];
        $rightQuantity = (float) $right['quantity'];
        $mergedQuantity = $leftQuantity + $rightQuantity;
        $childrenBySignature = [];

        foreach ([$left['sub_products'], $right['sub_products']] as $children) {
            foreach ($children as $child) {
                $signature = $this->requestedNodeSignature($child);
                if (!isset($childrenBySignature[$signature])) {
                    $childrenBySignature[$signature] = $child;
                    continue;
                }

                $childrenBySignature[$signature] = $this->mergeEquivalentNodes(
                    $childrenBySignature[$signature],
                    $child,
                    $mergedQuantity,
                );
            }
        }

        $left['quantity'] = $mergedQuantity;
        $left['unitQuantity'] = $this->normalizeQuantity(
            $mergedQuantity / $this->quantityDivisor($parentQuantity),
        );
        $left['sub_products'] = array_values($childrenBySignature);

        return $left;
    }

    private function normalizeReferenceId(mixed $reference): int
    {
        if (is_object($reference) && method_exists($reference, 'getId')) {
            return (int) $reference->getId();
        }

        if (is_array($reference)) {
            $reference = $reference['id'] ?? $reference['@id'] ?? null;
        }

        if (is_numeric($reference)) {
            return (int) $reference;
        }

        if (is_string($reference) && preg_match('/(\d+)(?:\D*)$/', $reference, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function quantityDivisor(float $quantity): float
    {
        return $quantity > 0 ? $quantity : 1.0;
    }

    private function normalizeQuantity(float $quantity): string
    {
        $normalized = rtrim(rtrim(number_format($quantity, 6, '.', ''), '0'), '.');

        return $normalized !== '' ? $normalized : '0';
    }
}
