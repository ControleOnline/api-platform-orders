<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductShowcaseItem;
use ControleOnline\Entity\Status;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
as Security;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use ControleOnline\Service\StatusService;


class OrderProductService
{
    use OrderProductServiceHelpers;

    public const LOYALTY_GIFT_COMMENT = 'Brinde fidelidade';

    private $request;
    private static $mainProduct = true;
    private static $calculateBefore = [];
    private OrderProductTreeNormalizer $treeNormalizer;
    private OrderProductTreeConsolidator $treeConsolidator;
    private ProposalProductCategoryGuard $proposalProductCategoryGuard;

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $peopleService,
        private OrderService $orderService,
        private StatusService $statusService,
        private RequestStack $requestStack,
        private OrderProductQueueService $orderProductQueueService,
        private InvoiceService $invoiceService,
        private ProductShowcaseCatalogService $productShowcaseCatalogService,
        ?OrderProductTreeNormalizer $treeNormalizer = null,
        ?ProposalProductCategoryGuard $proposalProductCategoryGuard = null,
    ) {
        $this->request = $this->requestStack->getCurrentRequest();
        $this->treeNormalizer = $treeNormalizer ?? new OrderProductTreeNormalizer();
        $this->treeConsolidator = new OrderProductTreeConsolidator(
            $this->manager,
            $this->treeNormalizer,
            fn (OrderProduct $parent, Product $product, ProductGroup $group, $qty) => $this->addSubproduct($parent, $product, $group, $qty),
            fn (?string $comment) => $this->normalizeOrderProductComment($comment),
        );
        $this->proposalProductCategoryGuard = $proposalProductCategoryGuard ?? new ProposalProductCategoryGuard();
    }

    public function addOrderProduct(
        Order $order,
        Product $product,
        $quantity,
        $price,
        ?ProductGroup $productGroup = null,
        ?Product $parentProduct = null,
        ?OrderProduct $orderParentProduct = null,
        ?ProductShowcaseItem $productShowcaseItem = null,
        ?string $comment = null
    ): OrderProduct
    {
        $OProduct = new OrderProduct();
        $OProduct->setOrder($order);
        $OProduct->setParentProduct($parentProduct);
        $OProduct->setOrderProduct($orderParentProduct);
        $OProduct->setProductGroup($productGroup);
        $OProduct->setQuantity($quantity);
        $OProduct->setProduct($product);
        $OProduct->setProductShowcaseItem($productShowcaseItem);
        if ($productShowcaseItem instanceof ProductShowcaseItem && $productShowcaseItem->getOutInventory()) {
            $OProduct->setOutInventory($productShowcaseItem->getOutInventory());
        }
        $OProduct->setShowInParentQueue(
            $this->shouldShowInParentQueue($productGroup, $parentProduct, $product)
        );
        $OProduct->setPrice($price);
        $OProduct->setTotal($price * $quantity);
        $OProduct->setComment($this->normalizeOrderProductComment($comment));
        $this->checkInventory($OProduct);
        $this->applyDefaultStatus($OProduct);
        $this->manager->persist($OProduct);
        $order->addOrderProduct($OProduct);
        $this->manager->flush();

        $this->orderProductQueueService->addProductToQueue($OProduct);
        return   $OProduct;
    }

    public function addProductsToOrder(Order $order, array $items): Order
    {
        foreach ($items as $item) {
            $product = $this->findProductReference($item['product'] ?? null);
            if (!$product instanceof Product) {
                throw new \InvalidArgumentException('Product not found');
            }

            $quantity = (float) ($item['quantity'] ?? 0);
            $comment = $this->normalizeOrderProductComment($item['comment'] ?? null);
            $productShowcaseItem = $this->productShowcaseCatalogService->resolveShowcaseForOrder($order, $product, $item);
            if ($productShowcaseItem instanceof ProductShowcaseItem) {
                $this->productShowcaseCatalogService->assertShowcaseItemStock($productShowcaseItem, $quantity);
            }

            $price = $productShowcaseItem instanceof ProductShowcaseItem
                ? $productShowcaseItem->getPrice()
                : $product->getPrice();
            $subProducts = $this->treeConsolidator->normalizeRequestedSubProducts($item, $quantity);
            $equivalentOrderProduct = $this->treeConsolidator->findEquivalentOrderProduct(
                $order,
                $product,
                $subProducts,
                $productShowcaseItem,
                $comment,
            );

            if ($equivalentOrderProduct instanceof OrderProduct) {
                $this->treeConsolidator->incrementEquivalentOrderProduct(
                    $equivalentOrderProduct,
                    $quantity,
                    $subProducts,
                );
                continue;
            }

            $rootOrderProduct = $this->addOrderProduct(
                $order,
                $product,
                $quantity,
                $price,
                comment: $comment,
                productShowcaseItem: $productShowcaseItem,
            );
            $this->treeConsolidator->addRequestedSubProducts($rootOrderProduct, $subProducts);
        }

        $this->manager->flush();
        $this->orderService->calculateGroupProductPrice($order);
        $this->orderService->calculateOrderPrice($order);
        $this->manager->refresh($order);

        return $order;
    }

    public function addProductsToOrderFromContent(
        Order $order,
        ?string $content
    ): Order {
        return $this->addProductsToOrder($order, $this->normalizeOrderProductItems($this->decodePayload($content)));
    }

    public function replaceProductsToOrder(Order $order, array $items): Order
    {
        $this->removeExistingOrderProducts($order);
        $this->manager->flush();
        $this->manager->refresh($order);

        $normalizedItems = $this->normalizeOrderProductItems($items);
        if (empty($normalizedItems)) {
            $this->orderService->calculateOrderPrice($order);
            $this->manager->refresh($order);
            self::$mainProduct = true;

            return $order;
        }

        self::$mainProduct = true;
        $this->addProductsToOrder($order, [reset($normalizedItems)]);
        $this->manager->refresh($order);
        self::$mainProduct = true;

        return $order;
    }

    /**
     * @param array<string, array{product: int, productGroup: int, quantity: float, unitQuantity: string}> $subProducts
     */
    /**
     * @param array<string, array{product: int, productGroup: int, quantity: float, unitQuantity: string}> $subProducts
     */
    public function replaceProductsToOrderFromContent(
        Order $order,
        ?string $content
    ): Order {
        return $this->replaceProductsToOrder($order, $this->decodePayload($content));
    }

    public function findOrderProductById(int $id): ?OrderProduct
    {
        return $this->manager->getRepository(OrderProduct::class)->find($id);
    }

    public function prePersist(OrderProduct $orderProduct): void
    {
        $this->guardDirectOrderProductMutation($orderProduct);
        $this->guardProposalProductCategory($orderProduct);
        $this->applyDefaultStatus($orderProduct);
    }

    public function preUpdate(OrderProduct $orderProduct): void
    {
        $this->guardDirectOrderProductMutation($orderProduct);
        $this->guardProposalProductCategory($orderProduct);
        $this->applyDefaultStatus($orderProduct);
    }

    public function addSubproduct(OrderProduct $orderProduct, Product $product, ProductGroup $productGroup, $quantity): OrderProduct
    {
        $productGroupProduct = $this->manager->getRepository(ProductGroupProduct::class)->findOneBy([
            'product' => $orderProduct->getProduct(),
            'productChild' => $product,
            'productGroup' => $productGroup
        ]) ?: $this->manager->getRepository(ProductGroupProduct::class)->findOneBy([
            'productChild' => $product,
            'productGroup' => $productGroup
        ]);

        if (!$productGroupProduct instanceof ProductGroupProduct) {
            throw new BadRequestHttpException('Product group item not found');
        }

        $OProduct = new OrderProduct();
        $OProduct->setOrder($orderProduct->getOrder());
        $OProduct->setParentProduct($orderProduct->getProduct());
        $OProduct->setOrderProduct($orderProduct);
        $OProduct->setProductGroup($productGroup);
        $OProduct->setShowInParentQueue(
            $productGroupProduct?->getShowInParentQueue() ?? true
        );
        $OProduct->setQuantity($quantity);
        $OProduct->setProduct($product);
        $OProduct->setPrice($productGroupProduct->getPrice());
        $OProduct->setTotal($productGroupProduct->getPrice() * $quantity);
        $this->checkInventory($OProduct);
        $this->applyDefaultStatus($OProduct);
        $this->manager->persist($OProduct);
        $orderProduct->addOrderProductComponent($OProduct);
        $orderProduct->getOrder()->addOrderProduct($OProduct);
        $this->manager->flush();

        $this->orderProductQueueService->addProductToQueue($OProduct);
        return $OProduct;
    }

    private function shouldShowInParentQueue(?ProductGroup $productGroup, ?Product $parentProduct, ?Product $childProduct): bool
    {
        if (
            !$productGroup instanceof ProductGroup ||
            !$parentProduct instanceof Product ||
            !$childProduct instanceof Product
        ) {
            return true;
        }

        $link = $this->manager->getRepository(ProductGroupProduct::class)->findOneBy([
            'product' => $parentProduct,
            'productGroup' => $productGroup,
            'productChild' => $childProduct,
        ]) ?: $this->manager->getRepository(ProductGroupProduct::class)->findOneBy([
            'productGroup' => $productGroup,
            'productChild' => $childProduct,
        ]);

        return !$link instanceof ProductGroupProduct || $link->getShowInParentQueue();
    }

    private function removeOrderProductBranch(OrderProduct $orderProduct): void
    {
        foreach ($orderProduct->getOrderProductComponents()->toArray() as $childOrderProduct) {
            $this->removeOrderProductBranch($childOrderProduct);
        }

        foreach ($orderProduct->getOrderProductQueues()->toArray() as $orderProductQueue) {
            $orderProduct->removeOrderProductQueue($orderProductQueue);
            $this->manager->remove($orderProductQueue);
        }

        $parentOrderProduct = $orderProduct->getOrderProduct();
        if ($parentOrderProduct instanceof OrderProduct) {
            $parentOrderProduct->removeOrderProductComponent($orderProduct);
        }

        $this->manager->remove($orderProduct);
    }

    private function cleanupOrderProductRelationsForRemoval(OrderProduct $orderProduct): void
    {
        foreach ($orderProduct->getOrderProductQueues()->toArray() as $orderProductQueue) {
            $orderProduct->removeOrderProductQueue($orderProductQueue);
            $this->manager->remove($orderProductQueue);
        }

        $parentOrderProduct = $orderProduct->getOrderProduct();
        if ($parentOrderProduct instanceof OrderProduct) {
            $parentOrderProduct->removeOrderProductComponent($orderProduct);
        }
    }

    private function replaceSubproducts(OrderProduct $orderProduct, array $subProducts): void
    {
        $existingSubproducts = $this->manager->getRepository(OrderProduct::class)->findBy([
            'orderProduct' => $orderProduct,
        ]);

        foreach ($existingSubproducts as $existingSubproduct) {
            $this->removeOrderProductBranch($existingSubproduct);
        }

        $this->manager->flush();
        $this->manager->refresh($orderProduct);

        $normalizedSubProducts = $this->treeNormalizer->normalizeRequestedChildren(
            $subProducts,
            (float) $orderProduct->getQuantity(),
        );
        $this->treeConsolidator->addRequestedSubProducts($orderProduct, $normalizedSubProducts);
    }

    private function checkInventory(OrderProduct &$orderProduct)
    {
        $order = $orderProduct->getOrder();
        $product =  $orderProduct->getProduct();

        if ($order->getOrderType() == 'sale' && !$orderProduct->getOutInventory()) {
            $showcaseItem = $orderProduct->getProductShowcaseItem();
            $orderProduct->setOutInventory(
                $showcaseItem instanceof ProductShowcaseItem && $showcaseItem->getOutInventory()
                    ? $showcaseItem->getOutInventory()
                    : $product->getDefaultOutInventory()
            );
        }

        if ($order->getOrderType() == 'purchase' && !$orderProduct->getInInventory())
            $orderProduct->setInInventory($product->getDefaultInInventory());
    }

    private function checkSubproducts(OrderProduct $orderProduct)
    {
        $json = json_decode($this->request->getContent(), true);

        if (isset($json['sub_products'])) {
            $this->replaceSubproducts(
                $orderProduct,
                is_array($json['sub_products']) ? $json['sub_products'] : []
            );
        }
    }

    private function applyDefaultStatus(OrderProduct $orderProduct): void
    {
        if ($orderProduct->getStatus() instanceof Status) {
            return;
        }

        $orderProduct->setStatus(
            $this->statusService->discoveryStatus('open', 'open', 'order_product')
        );
    }

    public function postUpdate(OrderProduct $orderProduct)
    {
        $this->postPersist($orderProduct);
    }
    
    public function postPersist(OrderProduct $orderProduct)
    {
        if (!self::$mainProduct || !$this->request) return;
        self::$mainProduct = false;

        $this->checkSubproducts($orderProduct);
        $this->checkInventory($orderProduct);
        $this->orderProductQueueService->addProductToQueue($orderProduct);
        return $this->calculateProductPrice($orderProduct);
    }

    public function preRemove(OrderProduct $orderProduct)
    {
        $this->guardDirectOrderProductMutation($orderProduct);

        if (!self::$mainProduct) return;
        self::$mainProduct = false;
        $order = $orderProduct->getOrder();
        $this->manager->persist($order->setPrice(0));

        $childOrderProducts = $this->manager->getRepository(OrderProduct::class)->findBy([
            'orderProduct' => $orderProduct,
        ]);

        foreach ($childOrderProducts as $childOrderProduct) {
            $this->removeOrderProductBranch($childOrderProduct);
        }

        $this->cleanupOrderProductRelationsForRemoval($orderProduct);

        self::$calculateBefore[] = $order;
    }

    private function calculateProductPrice(OrderProduct $orderProduct)
    {
        if ($this->isLoyaltyGiftOrderProduct($orderProduct)) {
            $orderProduct->setPrice(0);
            $orderProduct->setTotal(0);
            $this->manager->persist($orderProduct);
            $this->manager->flush();

            $this->orderService->calculateGroupProductPrice($orderProduct->getOrder());
            $this->orderService->calculateOrderPrice($orderProduct->getOrder());

            return $orderProduct;
        }

        $productGroupProduct = $this->manager->getRepository(ProductGroupProduct::class)->findOneBy([
            'product' => $orderProduct->getParentProduct(),
            'productChild' => $orderProduct->getProduct(),
            'productGroup' => $orderProduct->getProductGroup()
        ]) ?: $this->manager->getRepository(ProductGroupProduct::class)->findOneBy([
            'productChild' => $orderProduct->getProduct(),
            'productGroup' => $orderProduct->getProductGroup()
        ]) ?: $orderProduct->getProduct();

        $orderProduct->setPrice($productGroupProduct->getPrice());
        if ($productGroupProduct instanceof ProductGroupProduct) {
            $orderProduct->setShowInParentQueue($productGroupProduct->getShowInParentQueue());
        }
        $orderProduct->setTotal($productGroupProduct->getPrice() * $orderProduct->getQuantity());
        $this->manager->persist($orderProduct);
        $this->manager->flush();

        $this->orderService->calculateGroupProductPrice($orderProduct->getOrder());
        $this->orderService->calculateOrderPrice($orderProduct->getOrder());

        $this->invoiceService->payOrder($orderProduct->getOrder());
        $this->manager->refresh($orderProduct);

        return $orderProduct;
    }

    public function isLoyaltyGiftOrderProduct(OrderProduct $orderProduct): bool
    {
        return self::isLoyaltyGiftComment($orderProduct->getComment());
    }

    public static function isLoyaltyGiftComment(?string $comment): bool
    {
        return trim((string) $comment) === self::LOYALTY_GIFT_COMMENT;
    }

    public function  securityFilter(QueryBuilder $queryBuilder, $resourceClass = null, $applyTo = null, $rootAlias = null): void
    {
        if (!in_array('orders', $queryBuilder->getAllAliases(), true)) {
            $queryBuilder->innerJoin(sprintf('%s.order', $rootAlias), 'orders');
        }

        $this->orderService->securityFilter($queryBuilder, $resourceClass, $applyTo, 'orders');
    }


    private function guardProposalProductCategory(OrderProduct $orderProduct): void
    {
        $order = $orderProduct->getOrder();
        $product = $orderProduct->getProduct();

        if (
            !$order instanceof Order
            || !$product instanceof Product
            || $orderProduct->getOrderProduct() instanceof OrderProduct
            || $orderProduct->getParentProduct() instanceof Product
        ) {
            return;
        }

        $this->proposalProductCategoryGuard->assertOrderProductAllowed($order, $product);
    }

    public function __destruct()
    {
        foreach (self::$calculateBefore as $order) {
            $this->orderService->calculateGroupProductPrice($order);
            $this->orderService->calculateOrderPrice($order);
        }
    }









}
