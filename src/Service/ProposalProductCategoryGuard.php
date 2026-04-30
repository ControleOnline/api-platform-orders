<?php

namespace ControleOnline\Service;

final class ProposalProductCategoryGuard
{
    public function assertOrderProductAllowed(mixed $order, mixed $product): void
    {
        $expectedCategoryId = $this->resolveOrderCategoryId($order);
        if ($expectedCategoryId <= 0) {
            return;
        }

        if ($this->productHasCategory($product, $expectedCategoryId)) {
            return;
        }

        throw new \InvalidArgumentException(
            'O produto selecionado nao pertence a categoria do modelo da proposta.'
        );
    }

    private function resolveOrderCategoryId(mixed $order): int
    {
        $contract = $this->callGetter($order, 'getContract');
        $contractModel = $this->callGetter($contract, 'getContractModel');
        $category = $this->callGetter($contractModel, 'getCategory');

        return $this->normalizeReferenceId($category);
    }

    private function productHasCategory(mixed $product, int $expectedCategoryId): bool
    {
        $productCategories = $this->callGetter($product, 'getProductCategory');
        if (!is_iterable($productCategories)) {
            return false;
        }

        foreach ($productCategories as $productCategory) {
            $category = $this->callGetter($productCategory, 'getCategory');
            if ($this->normalizeReferenceId($category) === $expectedCategoryId) {
                return true;
            }
        }

        return false;
    }

    private function callGetter(mixed $value, string $method): mixed
    {
        if (!is_object($value) || !method_exists($value, $method)) {
            return null;
        }

        return $value->{$method}();
    }

    private function normalizeReferenceId(mixed $reference): int
    {
        if (is_object($reference) && method_exists($reference, 'getId')) {
            return (int) $reference->getId();
        }

        if (is_array($reference)) {
            return $this->normalizeReferenceId($reference['@id'] ?? $reference['id'] ?? null);
        }

        return (int) preg_replace('/\D+/', '', (string) $reference);
    }
}
