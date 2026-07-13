<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
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
    public const LOYALTY_GIFT_COMMENT = 'Brinde fidelidade';

    private $request;
    private static $mainProduct = true;
    private static $calculateBefore = [];

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $peopleService,
        private OrderService $orderService,
        private StatusService $statusService,
        private RequestStack $requestStack,
        private OrderProductQueueService $orderProductQueueService,
        private InvoiceService $invoiceService
    ) {
        $this->request = $this->requestStack->getCurrentRequest();
    }

    public function addOrderProduct(Order $order, Product $product, $quantity, $price, ?ProductGroup  $productGroup = null, ?Product $parentProduct = null, ?OrderProduct $orderParentProduct =  null): OrderProduct
    {
        $OProduct = new OrderProduct();
        $OProduct->setOrder($order);
        $OProduct->setParentProduct($parentProduct);
        $OProduct->setOrderProduct($orderParentProduct);
        $OProduct->setProductGroup($productGroup);
        $OProduct->setQuantity($quantity);
        $OProduct->setProduct($product);
        $OProduct->setShowInParentQueue(
            $this->shouldShowInParentQueue($productGroup, $parentProduct, $product)
        );
        $OProduct->setPrice($price);
        $OProduct->setTotal($price * $quantity);
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
            $price = $product->getPrice();
            $subProducts = $this->normalizeRequestedSubProducts($item, $quantity);
            $equivalentOrderProduct = $this->findEquivalentOrderProduct(
                $order,
                $product,
                $subProducts,
            );

            if ($equivalentOrderProduct instanceof OrderProduct) {
                $this->incrementEquivalentOrderProduct(
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
            );
            $this->addRequestedSubProducts($rootOrderProduct, $subProducts);
        }

        $this->manager->flush();
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
    private function findEquivalentOrderProduct(
        Order $order,
        Product $product,
        array $subProducts,
    ): ?OrderProduct {
        $requestedSignature = $this->buildRequestedSubProductsSignature($subProducts);

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

            if (
                $orderProduct->getOrderProduct() instanceof OrderProduct
                || $orderProduct->getParentProduct() instanceof Product
                || $orderProduct->getProductGroup() instanceof ProductGroup
            ) {
                continue;
            }

            if (
                $this->buildPersistedSubProductsSignature($orderProduct)
                === $requestedSignature
            ) {
                return $orderProduct;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{product: int, productGroup: int, quantity: float, unitQuantity: string}> $subProducts
     */
    private function incrementEquivalentOrderProduct(
        OrderProduct $orderProduct,
        float $quantity,
        array $subProducts,
    ): void {
        $nextQuantity = (float) $orderProduct->getQuantity() + $quantity;
        $orderProduct->setQuantity($nextQuantity);
        $orderProduct->setTotal((float) $orderProduct->getPrice() * $nextQuantity);

        $componentsBySignatureKey = [];
        foreach ($orderProduct->getOrderProductComponents() as $component) {
            if (!$component instanceof OrderProduct) {
                continue;
            }

            $signatureKey = $this->buildSubProductSignatureKey(
                $component->getProductGroup(),
                $component->getProduct(),
            );
            if ($signatureKey !== '') {
                $componentsBySignatureKey[$signatureKey] = $component;
            }
        }

        foreach ($subProducts as $signatureKey => $subProduct) {
            $component = $componentsBySignatureKey[$signatureKey] ?? null;
            if (!$component instanceof OrderProduct) {
                continue;
            }

            $nextComponentQuantity = (float) $component->getQuantity()
                + $subProduct['quantity'];
            $component->setQuantity($nextComponentQuantity);
            $component->setTotal(
                (float) $component->getPrice() * $nextComponentQuantity,
            );
        }
    }

    /**
     * @return array<string, array{product: int, productGroup: int, quantity: float, unitQuantity: string}>
     */
    private function normalizeRequestedSubProducts(array $item, float $rootQuantity): array
    {
        $normalizedSubProducts = [];
        $quantityDivisor = $rootQuantity > 0 ? $rootQuantity : 1.0;

        foreach (($item['sub_products'] ?? []) as $subProduct) {
            if (!is_array($subProduct)) {
                continue;
            }

            $productId = $this->normalizeReferenceId($subProduct['product'] ?? null);
            $productGroupId = $this->normalizeReferenceId(
                $subProduct['productGroup'] ?? null,
            );
            $quantity = (float) ($subProduct['quantity'] ?? 0);

            if ($productId <= 0 || $productGroupId <= 0 || $quantity <= 0) {
                continue;
            }

            $signatureKey = sprintf('%d:%d', $productGroupId, $productId);
            $normalizedSubProducts[$signatureKey] ??= [
                'product' => $productId,
                'productGroup' => $productGroupId,
                'quantity' => 0.0,
                'unitQuantity' => '0',
            ];
            $normalizedSubProducts[$signatureKey]['quantity'] += $quantity;
            $normalizedSubProducts[$signatureKey]['unitQuantity'] =
                $this->normalizeSignatureQuantity(
                    $normalizedSubProducts[$signatureKey]['quantity'] / $quantityDivisor,
                );
        }

        ksort($normalizedSubProducts);

        return $normalizedSubProducts;
    }

    /**
     * @param array<string, array{product: int, productGroup: int, quantity: float, unitQuantity: string}> $subProducts
     *
     * @return array<string, string>
     */
    private function buildRequestedSubProductsSignature(array $subProducts): array
    {
        return array_map(
            static fn (array $subProduct): string => $subProduct['unitQuantity'],
            $subProducts,
        );
    }

    /** @return array<string, string> */
    private function buildPersistedSubProductsSignature(
        OrderProduct $orderProduct,
    ): array {
        $signature = [];
        $rootQuantity = (float) $orderProduct->getQuantity();
        $quantityDivisor = $rootQuantity > 0 ? $rootQuantity : 1.0;

        foreach ($orderProduct->getOrderProductComponents() as $component) {
            if (!$component instanceof OrderProduct) {
                continue;
            }

            $signatureKey = $this->buildSubProductSignatureKey(
                $component->getProductGroup(),
                $component->getProduct(),
            );
            if ($signatureKey === '') {
                $signature['invalid:' . count($signature)] = 'invalid';
                continue;
            }

            $componentUnitQuantity = (float) $component->getQuantity()
                / $quantityDivisor;
            $signature[$signatureKey] = $this->normalizeSignatureQuantity(
                isset($signature[$signatureKey])
                    ? (float) $signature[$signatureKey] + $componentUnitQuantity
                    : $componentUnitQuantity,
            );
        }

        ksort($signature);

        return $signature;
    }

    private function buildSubProductSignatureKey(
        mixed $productGroup,
        mixed $product,
    ): string {
        if (!$productGroup instanceof ProductGroup || !$product instanceof Product) {
            return '';
        }

        $productGroupId = (int) $productGroup->getId();
        $productId = (int) $product->getId();

        return $productGroupId > 0 && $productId > 0
            ? sprintf('%d:%d', $productGroupId, $productId)
            : '';
    }

    private function normalizeSignatureQuantity(float $quantity): string
    {
        $normalizedQuantity = rtrim(
            rtrim(number_format($quantity, 6, '.', ''), '0'),
            '.',
        );

        return $normalizedQuantity !== '' ? $normalizedQuantity : '0';
    }

    /**
     * @param array<string, array{product: int, productGroup: int, quantity: float, unitQuantity: string}> $subProducts
     */
    private function addRequestedSubProducts(
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

            $this->addSubproduct(
                $orderProduct,
                $product,
                $productGroup,
                $subProduct['quantity'],
            );
        }
    }

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
        $this->applyDefaultStatus($orderProduct);
    }

    public function preUpdate(OrderProduct $orderProduct): void
    {
        $this->guardDirectOrderProductMutation($orderProduct);
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

        $normalizedSubProducts = [];
        foreach ($subProducts as $subproduct) {
            $productId = $this->normalizeReferenceId($subproduct['product'] ?? null);
            $productGroupId = $this->normalizeReferenceId($subproduct['productGroup'] ?? null);
            $quantity = (float) ($subproduct['quantity'] ?? 0);

            if ($productId <= 0 || $productGroupId <= 0 || $quantity <= 0) {
                continue;
            }

            $normalizedSubProducts[sprintf('%d:%d', $productGroupId, $productId)] = [
                'product' => $productId,
                'productGroup' => $productGroupId,
                'quantity' => $quantity,
            ];
        }

        foreach ($normalizedSubProducts as $subproduct) {
            $product = $this->manager->getRepository(Product::class)->find($subproduct['product']);
            $productGroup =  $this->manager->getRepository(ProductGroup::class)->find($subproduct['productGroup']);
            if (!$product instanceof Product || !$productGroup instanceof ProductGroup) {
                continue;
            }
            $this->addSubproduct($orderProduct, $product, $productGroup, $subproduct['quantity']);
        }
    }

    private function checkInventory(OrderProduct &$orderProduct)
    {
        $order = $orderProduct->getOrder();
        $product =  $orderProduct->getProduct();

        if ($order->getOrderType() == 'sale' && !$orderProduct->getOutInventory())
            $orderProduct->setOutInventory($product->getDefaultOutInventory());

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

    public function __destruct()
    {
        foreach (self::$calculateBefore as $order) {
            $this->orderService->calculateGroupProductPrice($order);
            $this->orderService->calculateOrderPrice($order);
        }
    }

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
