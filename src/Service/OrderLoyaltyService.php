<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleLink;
use ControleOnline\Entity\Product;
use ControleOnline\Event\EntityChangedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @agents Shop loyalty lifecycle.
 *
 * Closed eligible sales stamp the latest open fidelity card through the direct main order link.
 * When a card is already full, the next closed eligible sale with the configured gift closes that card.
 * When that next sale does not carry the gift, it starts the next open card instead.
 */
class OrderLoyaltyService implements EventSubscriberInterface
{
    private const CONFIG_ENABLED = 'shop-loyalty-coupons-enabled';
    private const CONFIG_PRODUCT_IDS = 'shop-loyalty-product-ids';
    private const CONFIG_REQUIRED_SALES = 'shop-loyalty-required-sales';
    private const CONFIG_GIFT_PRODUCT_ID = 'shop-loyalty-gift-product-id';

    private const INFO_REQUIRED_SALES = 'loyalty_required_sales';
    private const INFO_GIFT_PRODUCT_ID = 'loyalty_gift_product_id';
    private const INFO_REWARD_ORDER_ID = 'loyalty_reward_order_id';
    private const INFO_REWARD_PRODUCT_ID = 'loyalty_reward_product_id';
    private const INFO_REWARD_RESERVED_AT = 'loyalty_reward_reserved_at';
    private const INFO_REWARD_REDEEMED_AT = 'loyalty_reward_redeemed_at';

    private bool $handling = false;

    public function __construct(
        private EntityManagerInterface $manager,
        private ConfigService $configService,
        private StatusService $statusService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityChangedEvent::class => 'onEntityChanged',
        ];
    }

    public function onEntityChanged(EntityChangedEvent $event): void
    {
        /*
         * @agents Loyalty updates run as a single-pass side effect of order persistence.
         * This subscriber listens to the shared EntityChangedEvent emitted by DefaultEventListener,
         * so the rule stays decoupled from order services and avoids circular dependencies.
         */
        if (
            $this->handling ||
            !in_array($event->getPhase(), ['postPersist', 'postUpdate'], true)
        ) {
            return;
        }

        $order = $event->getEntity();
        if (!$order instanceof Order || $this->isFidelityOrder($order)) {
            return;
        }

        $this->processOrder($order);
    }

    /**
     * Applies the loyalty lifecycle to one persisted sale.
     *
     * This public entry point is also used by the idempotent backfill command. A sale
     * already linked to a fidelity card is always ignored.
     */
    public function processOrder(Order $order): bool
    {
        if ($this->handling || !$this->isProcessableSale($order)) {
            return false;
        }

        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return false;
        }

        $settings = $this->resolveSettings($provider);
        if (!$settings['enabled']) {
            return false;
        }

        $this->handling = true;
        try {
            return $this->processClosedSale($order, $settings);
        } finally {
            $this->handling = false;
        }
    }

    /**
     * Read-only eligibility check used by the default dry-run backfill mode.
     */
    public function canProcessOrder(Order $order): bool
    {
        if (!$this->isProcessableSale($order)) {
            return false;
        }

        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return false;
        }

        $settings = $this->resolveSettings($provider);
        if (!$settings['enabled']) {
            return false;
        }

        return $this->isEligibleSale($order, $settings)
            || (
                $this->findGiftProductInSale($order, $settings) instanceof Product
                && $this->findRewardableCard($order, $settings) instanceof Order
            );
    }

    /**
     * @agents Closed eligible sales only touch loyalty. Any other order type stays outside this lifecycle.
     *
     * @param array{
     *     enabled:bool,
     *     giftProduct:?Product,
     *     giftProductId:?int,
     *     productIds:array<int,bool>,
     *     requiredSales:int
     * } $settings
     */
    private function processClosedSale(Order $sale, array $settings): bool
    {
        /*
         * @agents A closed eligible sale follows one of two paths:
         * either it closes a full card with the configured gift, or it stamps the next open card.
         */
        $giftProduct = $this->findGiftProductInSale($sale, $settings);
        $rewardableCard = $this->findRewardableCard($sale, $settings);

        if ($rewardableCard instanceof Order && $giftProduct instanceof Product) {
            $this->linkSaleToCard($sale, $rewardableCard);
            $this->closeCardWithReward($rewardableCard, $sale, $giftProduct);

            return true;
        }

        if (!$this->isEligibleSale($sale, $settings)) {
            return false;
        }

        $stampCard = $this->findStampCard($sale, $settings);
        if (!$stampCard instanceof Order) {
            $stampCard = $this->createCard($sale, $settings);
        }

        $this->linkSaleToCard($sale, $stampCard);

        return true;
    }

    /**
     * @param array{
     *     enabled:bool,
     *     giftProduct:?Product,
     *     giftProductId:?int,
     *     productIds:array<int,bool>,
     *     requiredSales:int
     * } $settings
     */
    private function findRewardableCard(Order $sale, array $settings): ?Order
    {
        /*
         * @agents Only already-full open cards can be closed by a gift-bearing sale.
         * Cards that already redeemed a reward are skipped so the reward is not applied twice.
         */
        foreach ($this->findOpenCards($sale) as $card) {
            $info = $this->readOrderInfo($card);
            if (!empty($info[self::INFO_REWARD_REDEEMED_AT])) {
                continue;
            }

            if ($this->countCardStamps($card, $settings) >= $this->getRequiredSales($settings)) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @param array{
     *     enabled:bool,
     *     giftProduct:?Product,
     *     giftProductId:?int,
     *     productIds:array<int,bool>,
     *     requiredSales:int
     * } $settings
     */
    private function findStampCard(Order $sale, array $settings): ?Order
    {
        /*
         * @agents Prefer the latest open card that still has room for stamps.
         * If none exists, the caller creates a new fidelity card and links the sale to it.
         */
        foreach ($this->findOpenCards($sale) as $card) {
            $info = $this->readOrderInfo($card);
            if (!empty($info[self::INFO_REWARD_REDEEMED_AT])) {
                continue;
            }

            if ($this->countCardStamps($card, $settings) < $this->getRequiredSales($settings)) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @return Order[]
     */
    private function findOpenCards(Order $order): array
    {
        $cards = $this->manager->getRepository(Order::class)->findBy(
            [
                'provider' => $order->getProvider(),
                'client' => $order->getClient(),
                'orderType' => Order::ORDER_TYPE_FIDELITY,
            ],
            [
                'orderDate' => 'DESC',
                'id' => 'DESC',
            ],
            50,
        );

        return array_values(array_filter(
            $cards,
            fn ($card): bool => $card instanceof Order && $this->isOpenCard($card),
        ));
    }

    /**
     * @param array{
     *     enabled:bool,
     *     giftProduct:?Product,
     *     giftProductId:?int,
     *     productIds:array<int,bool>,
     *     requiredSales:int
     * } $settings
     */
    private function createCard(Order $sale, array $settings): Order
    {
        /*
         * @agents New fidelity cards are born open and carry the rule metadata
         * so the snapshot service can rebuild progress without guessing from raw orders.
         */
        $status = $this->statusService->discoveryStatus('open', 'open', 'order');

        $card = new Order();
        $card->setProvider($sale->getProvider());
        $card->setClient($sale->getClient());
        $card->setPayer($sale->getClient());
        $card->setApp($sale->getApp() ?: 'SHOP');
        $card->setOrderType(Order::ORDER_TYPE_FIDELITY);
        $card->setStatus($status);
        $card->setPrice(0);
        $card->setComments('Cartao fidelidade');
        $this->writeOrderInfo($card, [
            self::INFO_REQUIRED_SALES => $settings['requiredSales'],
            self::INFO_GIFT_PRODUCT_ID => $settings['giftProductId'],
        ]);

        $this->manager->persist($card);
        $this->manager->flush();

        return $card;
    }

    private function linkSaleToCard(Order $sale, Order $card): void
    {
        $cardId = $this->normalizeId($card->getId());
        if ($cardId === null) {
            return;
        }

        /*
         * @agents The direct main order link is the only source of truth for loyalty stamps.
         * Do not mirror the card id in metadata.
         */
        $sale->setMainOrder($card);
        $sale->setMainOrderId($cardId);

        $this->manager->persist($sale);
        $this->manager->flush();
    }

    private function closeCardWithReward(Order $card, Order $rewardSale, Product $giftProduct): void
    {
        /*
         * @agents Closing the card marks the reward as reserved and redeemed at the same time.
         * That keeps the snapshot stable and prevents the same reward sale from being counted again.
         */
        $rewardSaleId = $this->normalizeId($rewardSale->getId());
        $giftProductId = $this->normalizeId($giftProduct->getId());
        if ($rewardSaleId === null || $giftProductId === null) {
            return;
        }

        $now = (new \DateTimeImmutable())->format(DATE_ATOM);
        $info = $this->readOrderInfo($card);
        $info[self::INFO_REWARD_ORDER_ID] = $rewardSaleId;
        $info[self::INFO_REWARD_PRODUCT_ID] = $giftProductId;
        $info[self::INFO_REWARD_RESERVED_AT] = $now;
        $info[self::INFO_REWARD_REDEEMED_AT] = $now;
        $this->writeOrderInfo($card, $info);

        $card->setStatus($this->statusService->discoveryStatus('closed', 'closed', 'order'));

        $this->manager->persist($card);
        $this->manager->flush();
    }

    /**
     * @param array{
     *     enabled:bool,
     *     giftProduct:?Product,
     *     giftProductId:?int,
     *     productIds:array<int,bool>,
     *     requiredSales:int
     * } $settings
     */
    private function countCardStamps(Order $card, array $settings): int
    {
        /*
         * @agents Only closed, eligible, non-reward child sales linked directly to the current card count as stamps.
         */
        $repository = $this->manager->getRepository(Order::class);
        $sales = $repository->findBy(
            [
                'mainOrderId' => $card->getId(),
                'orderType' => Order::ORDER_TYPE_SALE,
            ],
            [
                'orderDate' => 'DESC',
            ],
            200,
        );

        $count = 0;
        $seen = [];
        foreach ($sales as $sale) {
            if (
                !$sale instanceof Order ||
                !$this->isClosedOrder($sale) ||
                !$this->isSaleLinkedToCard($sale, $card) ||
                !$this->isEligibleSale($sale, $settings) ||
                $this->isRewardOrderForCard($sale, $card)
            ) {
                continue;
            }

            $saleId = $this->normalizeId($sale->getId()) ?? spl_object_hash($sale);
            if (isset($seen[$saleId])) {
                continue;
            }

            $seen[$saleId] = true;
            $count++;
        }

        return $count;
    }

    /**
     * @param array{
     *     enabled:bool,
     *     giftProduct:?Product,
     *     giftProductId:?int,
     *     productIds:array<int,bool>,
     *     requiredSales:int
     * } $settings
     */
    private function getRequiredSales(array $settings): int
    {
        /*
         * The program configuration is intentionally authoritative. Administrators
         * can change the target while cards are open, and both the lifecycle and
         * the SHOP snapshot must immediately use the same current value.
         * Card metadata remains an audit record of the value at creation time.
         */
        return max(1, (int) $settings['requiredSales']);
    }

    private function isRewardOrderForCard(Order $sale, Order $card): bool
    {
        $saleId = $this->normalizeId($sale->getId());
        if ($saleId === null) {
            return false;
        }

        $info = $this->readOrderInfo($card);
        $rewardOrderId = $this->normalizeId($info[self::INFO_REWARD_ORDER_ID] ?? null);

        return $rewardOrderId !== null && $rewardOrderId === $saleId;
    }

    private function isSaleLinkedToCard(Order $sale, Order $card): bool
    {
        $cardId = (int) ($card->getId() ?? 0);
        if ($cardId <= 0) {
            return false;
        }

        if ($this->normalizeId($sale->getMainOrderId()) === $cardId) {
            return true;
        }

        $mainOrder = $sale->getMainOrder();

        return $mainOrder instanceof Order
            && $this->isFidelityOrder($mainOrder)
            && (int) ($mainOrder->getId() ?? 0) === $cardId;
    }

    private function findGiftProductInSale(Order $sale, array $settings): ?Product
    {
        $giftProductIds = $settings['giftProductIds'] ?? [];
        $giftProductSkus = $settings['giftProductSkus'] ?? [];

        foreach ($sale->getOrderProducts() as $orderProduct) {
            if (!$orderProduct instanceof OrderProduct || $orderProduct->getOrderProduct() instanceof OrderProduct) {
                continue;
            }

            $product = $orderProduct->getProduct();
            if (
                $product instanceof Product &&
                $this->matchesProduct($product, $giftProductIds, $giftProductSkus) &&
                (float) $orderProduct->getTotal() <= 0
            ) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @param array{
     *     enabled:bool,
     *     giftProduct:?Product,
     *     giftProductId:?int,
     *     productIds:array<int,bool>,
     *     requiredSales:int
     * } $settings
     */
    private function isEligibleSale(Order $sale, array $settings): bool
    {
        /*
         * @agents Eligibility is driven by root-level products with positive value.
         * Gift lines are ignored so the reward item itself never increments the stamp count.
         */
        $eligibleProductIds = $settings['productIds'] ?? [];
        $eligibleProductSkus = $settings['productSkus'] ?? [];
        if (empty($eligibleProductIds) && empty($eligibleProductSkus)) {
            return false;
        }

        foreach ($sale->getOrderProducts() as $orderProduct) {
            if (!$orderProduct instanceof OrderProduct || $orderProduct->getOrderProduct() instanceof OrderProduct) {
                continue;
            }

            $product = $orderProduct->getProduct();
            if (
                $product instanceof Product &&
                $this->matchesProduct($product, $eligibleProductIds, $eligibleProductSkus) &&
                (float) $orderProduct->getTotal() > 0 &&
                !OrderProductService::isLoyaltyGiftComment($orderProduct->getComment())
            ) {
                return true;
            }
        }

        return false;
    }

    private function isClosedOrder(Order $order): bool
    {
        $status = $this->normalizeText($order->getStatus()?->getStatus());
        $realStatus = $this->normalizeText($order->getStatus()?->getRealStatus());

        return $status === 'closed' || $realStatus === 'closed';
    }

    private function isOpenCard(Order $card): bool
    {
        $status = $this->normalizeText($card->getStatus()?->getStatus());
        $realStatus = $this->normalizeText($card->getStatus()?->getRealStatus());

        return $status === 'open' || $realStatus === 'open';
    }

    private function isFidelityOrder(Order $order): bool
    {
        return $this->normalizeText($order->getOrderType()) === Order::ORDER_TYPE_FIDELITY;
    }

    private function isSaleOrder(Order $order): bool
    {
        return $this->normalizeText($order->getOrderType()) === Order::ORDER_TYPE_SALE;
    }

    private function isProcessableSale(Order $order): bool
    {
        return $order->getProvider() instanceof People
            && $order->getClient() instanceof People
            && $this->isSaleOrder($order)
            && $this->isClosedOrder($order)
            && !$this->resolveLinkedFidelityCard($order) instanceof Order;
    }

    /**
     * @return array{
     *     enabled:bool,
     *     giftProduct:?Product,
     *     giftProductId:?int,
     *     giftProductIds:array<int,bool>,
     *     giftProductSkus:array<string,bool>,
     *     productIds:array<int,bool>,
     *     productSkus:array<string,bool>,
     *     requiredSales:int
     * }
     */
    private function resolveSettings(People $provider): array
    {
        $programProvider = $this->resolveProgramProvider($provider);
        $enabled = $this->normalizeBoolean(
            $this->configService->getConfig($programProvider, self::CONFIG_ENABLED)
        );
        $productIds = $this->normalizeIds(
            $this->configService->getConfig($programProvider, self::CONFIG_PRODUCT_IDS)
        );
        $requiredSales = max(
            0,
            (int) $this->normalizeNumber(
                $this->configService->getConfig($programProvider, self::CONFIG_REQUIRED_SALES)
            )
        );
        $giftProductId = $this->normalizeId(
            $this->configService->getConfig($programProvider, self::CONFIG_GIFT_PRODUCT_ID)
        );
        $giftProduct = $giftProductId
            ? $this->manager->getRepository(Product::class)->find($giftProductId)
            : null;
        $productSkus = $this->resolveProductSkus($productIds);
        $giftProductIds = $giftProductId === null ? [] : [$giftProductId => true];
        $giftSku = $giftProduct instanceof Product ? $this->normalizeSku($giftProduct->getSku()) : null;

        return [
            'enabled' => $enabled
                && !empty($productIds)
                && $requiredSales > 0
                && $giftProduct instanceof Product,
            'giftProduct' => $giftProduct,
            'giftProductId' => $giftProductId,
            'giftProductIds' => $giftProductIds,
            'giftProductSkus' => $giftSku === null ? [] : [$giftSku => true],
            'productIds' => array_fill_keys($productIds, true),
            'productSkus' => $productSkus,
            'requiredSales' => $requiredSales,
        ];
    }

    private function resolveProgramProvider(People $provider): People
    {
        /*
         * An explicit provider-level switch is an override. When it is absent, an
         * active franchise inherits the program owned by its matrix company.
         */
        if ($this->configService->getConfig($provider, self::CONFIG_ENABLED) !== null) {
            return $provider;
        }

        $link = $this->manager->getRepository(PeopleLink::class)->findOneBy([
            'people' => $provider,
            'linkType' => 'franchisee',
            'enable' => true,
        ]);
        $programProvider = $link instanceof PeopleLink ? $link->getCompany() : null;

        return $programProvider instanceof People && $programProvider->getEnabled()
            ? $programProvider
            : $provider;
    }

    /**
     * @param int[] $productIds
     *
     * @return array<string, bool>
     */
    private function resolveProductSkus(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $products = $this->manager->getRepository(Product::class)->findBy(['id' => $productIds]);
        $skus = [];
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $sku = $this->normalizeSku($product->getSku());
            if ($sku !== null) {
                $skus[$sku] = true;
            }
        }

        return $skus;
    }

    /**
     * @param array<int, bool> $productIds
     * @param array<string, bool> $productSkus
     */
    private function matchesProduct(Product $product, array $productIds, array $productSkus): bool
    {
        $productId = $this->normalizeId($product->getId());
        if ($productId !== null && isset($productIds[$productId])) {
            return true;
        }

        $sku = $this->normalizeSku($product->getSku());

        return $sku !== null && isset($productSkus[$sku]);
    }

    private function normalizeSku(mixed $value): ?string
    {
        $sku = strtoupper(trim((string) $value));
        if ($sku === '') {
            return null;
        }

        if (!ctype_digit($sku)) {
            return $sku;
        }

        $normalized = ltrim($sku, '0');

        return $normalized === '' ? '0' : $normalized;
    }

    private function resolveLinkedFidelityCard(Order $order): ?Order
    {
        /*
         * @agents Resolve the canonical card only from the direct main order link.
         */
        $mainOrder = $order->getMainOrder();
        if ($mainOrder instanceof Order && $this->isFidelityOrder($mainOrder)) {
            return $mainOrder;
        }

        $mainOrderId = $this->normalizeId($order->getMainOrderId());
        if ($mainOrderId === null) {
            return null;
        }

        $mainOrder = $this->manager->getRepository(Order::class)->find($mainOrderId);

        return $mainOrder instanceof Order && $this->isFidelityOrder($mainOrder)
            ? $mainOrder
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readOrderInfo(Order $order): array
    {
        $raw = $order->getOtherInformations();
        if (is_array($raw)) {
            return $raw;
        }

        if (is_object($raw)) {
            $raw = json_encode($raw);
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeOrderInfo(Order $order, array $info): void
    {
        $order->setOtherInformations((object) $info);
    }

    private function normalizeText(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = $this->normalizeText($this->decodeJsonScalar($value));

        return in_array(
            $normalized,
            ['1', 'true', 'yes', 'sim', 'on', 'enabled', 'ativo'],
            true,
        );
    }

    /**
     * @return array<int>
     */
    private function normalizeIds(mixed $value): array
    {
        $decoded = $this->decodeJsonScalar($value);
        $source = is_array($decoded) ? $decoded : preg_split('/\r?\n|,/', (string) $decoded);
        $ids = [];

        foreach ($source ?: [] as $item) {
            $id = $this->normalizeId($item);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function normalizeNumber(mixed $value): int
    {
        $decoded = $this->decodeJsonScalar($value);
        if (is_numeric($decoded)) {
            return (int) $decoded;
        }

        return 0;
    }

    private function normalizeId(mixed $value): ?int
    {
        $decoded = $this->decodeJsonScalar($value);
        if (is_int($decoded)) {
            return $decoded > 0 ? $decoded : null;
        }

        if (is_numeric($decoded)) {
            $id = (int) $decoded;

            return $id > 0 ? $id : null;
        }

        if (!is_string($decoded)) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $decoded);
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        $id = (int) $normalized;

        return $id > 0 ? $id : null;
    }

    private function decodeJsonScalar(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        if (!in_array($trimmed[0], ['{', '[', '"'], true)) {
            return $trimmed;
        }

        $decoded = json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $trimmed;
    }
}
