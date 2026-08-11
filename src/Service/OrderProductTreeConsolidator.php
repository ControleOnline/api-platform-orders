<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductShowcaseItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds, increments and persists recursive OrderProduct component trees.
 */
final class OrderProductTreeConsolidator
{
    public function __construct(
        private EntityManagerInterface $manager,
        private OrderProductTreeNormalizer $treeNormalizer,
        private \Closure $addSubproduct,
        private \Closure $normalizeComment,
    ) {
    }

    public function normalizeRequestedSubProducts(array $item, float $rootQuantity): array
    {
        return $this->treeNormalizer->normalizeRequestedChildren(
            is_array($item['sub_products'] ?? null) ? $item['sub_products'] : [],
            $rootQuantity,
        );
    }

    public function findEquivalentOrderProduct(
        Order $order,
        Product $product,
        array $subProducts,
        ?ProductShowcaseItem $productShowcaseItem = null,
        ?string $comment = null,
    ): ?OrderProduct {
        $requestedSignature = $this->treeNormalizer->requestedTreeSignature($subProducts);
        $normalizedRequestedComment = ($this->normalizeComment)($comment);

        foreach ($order->getOrderProducts() as $orderProduct) {
            if (!$orderProduct instanceof OrderProduct) {
                continue;
            }

            $currentProduct = $orderProduct->getProduct();
            $isSameProduct = $currentProduct === $product
                || (
                    $currentProduct instanceof Product
                    && $currentProduct->getId()
                    && $currentProduct->getId() === $product->getId()
                );

            if (!$isSameProduct) {
                continue;
            }

            $currentShowcaseItem = $orderProduct->getProductShowcaseItem();
            $currentShowcaseItemId = $currentShowcaseItem instanceof ProductShowcaseItem
                ? $currentShowcaseItem->getId()
                : 0;
            $requestedShowcaseItemId = $productShowcaseItem instanceof ProductShowcaseItem
                ? $productShowcaseItem->getId()
                : 0;
            if ($currentShowcaseItemId !== $requestedShowcaseItemId) {
                continue;
            }

            if (($this->normalizeComment)($orderProduct->getComment()) !== $normalizedRequestedComment) {
                continue;
            }

            if (
                $orderProduct->getOrderProduct() instanceof OrderProduct
                || $orderProduct->getParentProduct() instanceof Product
                || $orderProduct->getProductGroup() instanceof ProductGroup
            ) {
                continue;
            }

            if ($this->treeNormalizer->persistedTreeSignature($orderProduct) === $requestedSignature) {
                return $orderProduct;
            }
        }

        return null;
    }

    public function incrementEquivalentOrderProduct(
        OrderProduct $orderProduct,
        float $quantity,
        array $subProducts,
    ): void {
        $currentQuantity = (float) $orderProduct->getQuantity();
        $componentsBySignature = [];
        foreach ($orderProduct->getOrderProductComponents() as $component) {
            if (!$component instanceof OrderProduct) {
                continue;
            }

            $signature = $this->treeNormalizer->persistedNodeSignature(
                $component,
                $currentQuantity,
            );
            $componentsBySignature[$signature][] = $component;
        }

        $nextQuantity = (float) $orderProduct->getQuantity() + $quantity;
        $orderProduct->setQuantity($nextQuantity);
        $orderProduct->setTotal((float) $orderProduct->getPrice() * $nextQuantity);

        foreach ($subProducts as $subProduct) {
            $signature = $this->treeNormalizer->requestedNodeSignature($subProduct);
            $matchingComponents = $componentsBySignature[$signature] ?? [];
            $component = array_shift($matchingComponents);
            $componentsBySignature[$signature] = $matchingComponents;
            if (!$component instanceof OrderProduct) {
                continue;
            }

            $this->incrementEquivalentOrderProduct(
                $component,
                (float) $subProduct['quantity'],
                $subProduct['sub_products'],
            );
        }
    }

    public function addRequestedSubProducts(
        OrderProduct $orderProduct,
        array $subProducts,
    ): void {
        foreach ($subProducts as $subProduct) {
            $product = $this->manager->getRepository(Product::class)->find(
                $subProduct['product'],
            );
            $productGroup = $this->manager->getRepository(ProductGroup::class)->find(
                $subProduct['productGroup'],
            );
            if (!$product instanceof Product || !$productGroup instanceof ProductGroup) {
                continue;
            }

            $childOrderProduct = ($this->addSubproduct)(
                $orderProduct,
                $product,
                $productGroup,
                $subProduct['quantity'],
            );
            $this->addRequestedSubProducts(
                $childOrderProduct,
                $subProduct['sub_products'],
            );
        }
    }
}
