<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Event\EntityChangedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @agents Shop loyalty lifecycle.
 *
 * Closed eligible sales stamp the latest open fidelity card.
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
    private const INFO_CARD_ID = 'loyalty_card_id';
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
         * The guard prevents recursive updates when the subscriber writes back to the same order tree.
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

        $provider = $order->getProvider();
        $client = $order->getClient();
        if (!$provider instanceof People || !$client instanceof People) {
            return;
        }

        $settings = $this->resolveSettings($provider);
        if (!$settings['enabled']) {
            return;
        }

        if (
            !$this->isSaleOrder($order) ||
            !$this->isClosedOrder($order) ||
            $this->resolveLinkedFidelityCard($order) instanceof Order
        ) {
            return;
        }

        $this->handling = true;
        try {
            $this->processClosedSale($order, $settings);
        } finally {
            $this->handling = false;
        }
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
    private function processClosedSale(Order $sale, array $settings): void
    {
        /*
         * @agents A closed eligible sale follows one of two paths:
         * either it closes a full card with the configured gift, or it stamps the next open card.
         */
        if (!$this->isEligibleSale($sale, $settings)) {
            return;
        }

        $giftProduct = $settings['giftProduct'] ?? null;
        $hasGiftProduct = $giftProduct instanceof Product && $this->saleHasGiftProduct($sale, $giftProduct);
        $rewardableCard = $this->findRewardableCard($sale, $settings);

        if ($rewardableCard instanceof Order && $hasGiftProduct) {
            $this->linkSaleToCard($sale, $rewardableCard);
            $this->closeCardWithReward($rewardableCard, $sale, $giftProduct);

            return;
        }

        $stampCard = $this->findStampCard($sale, $settings);
        if (!$stampCard instanceof Order) {
            $stampCard = $this->createCard($sale, $settings);
        }

        $this->linkSaleToCard($sale, $stampCard);
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

            if ($this->countCardStamps($card, $settings) >= $this->getCardRequiredSales($card, $settings)) {
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
         * @agents Prefer the oldest open card that still has room for stamps.
         * If none exists, the caller creates a new fidelity card and links the sale to it.
         */
        foreach ($this->findOpenCards($sale) as $card) {
            $info = $this->readOrderInfo($card);
            if (!empty($info[self::INFO_REWARD_REDEEMED_AT])) {
                continue;
            }

            if ($this->countCardStamps($card, $settings) < $this->getCardRequiredSales($card, $settings)) {
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
         * @agents Preserve any existing commercial parent. The loyalty card stays mirrored in metadata
         * so the snapshot service and UI can still resolve the fidelity chain when another flow owns mainOrderId.
         */
        $currentMainOrderId = $this->normalizeId($sale->getMainOrderId());
        $currentMainOrder = $sale->getMainOrder();
        if (
            $currentMainOrderId === null ||
            ($currentMainOrder instanceof Order && $this->isFidelityOrder($currentMainOrder))
        ) {
            $sale->setMainOrder($card);
            $sale->setMainOrderId($cardId);
        }

        $info = $this->readOrderInfo($sale);
        $info[self::INFO_CARD_ID] = $cardId;
        $this->writeOrderInfo($sale, $info);

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
         * @agents Only closed, eligible, non-reward child sales count as stamps on the current card.
         */
        $repository = $this->manager->getRepository(Order::class);
        $sales = array_merge(
            $repository->findBy([
                'mainOrderId' => $card->getId(),
                'orderType' => Order::ORDER_TYPE_SALE,
            ]),
            $repository->findBy(
                [
                    'provider' => $card->getProvider(),
                    'client' => $card->getClient(),
                    'orderType' => Order::ORDER_TYPE_SALE,
                ],
                [
                    'orderDate' => 'DESC',
                ],
                200,
            ),
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
    private function getCardRequiredSales(Order $card, array $settings): int
    {
        $info = $this->readOrderInfo($card);
        $requiredSales = (int) ($info[self::INFO_REQUIRED_SALES] ?? 0);

        return max(1, $requiredSales ?: (int) $settings['requiredSales']);
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

        $info = $this->readOrderInfo($sale);
        if ($this->normalizeId($info[self::INFO_CARD_ID] ?? null) === $cardId) {
            return true;
        }

        if ($this->normalizeId($sale->getMainOrderId()) === $cardId) {
            return true;
        }

        $mainOrder = $sale->getMainOrder();

        return $mainOrder instanceof Order
            && $this->isFidelityOrder($mainOrder)
            && (int) ($mainOrder->getId() ?? 0) === $cardId;
    }

    private function saleHasGiftProduct(Order $sale, Product $giftProduct): bool
    {
        $giftProductId = $this->normalizeId($giftProduct->getId());
        if ($giftProductId === null) {
            return false;
        }

        foreach ($sale->getOrderProducts() as $orderProduct) {
            if (!$orderProduct instanceof OrderProduct || $orderProduct->getOrderProduct() instanceof OrderProduct) {
                continue;
            }

            $productId = $this->normalizeId($orderProduct->getProduct()?->getId());
            if (
                $productId === $giftProductId &&
                (float) $orderProduct->getTotal() <= 0
            ) {
                return true;
            }
        }

        return false;
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
        if (empty($eligibleProductIds)) {
            return false;
        }

        foreach ($sale->getOrderProducts() as $orderProduct) {
            if (!$orderProduct instanceof OrderProduct || $orderProduct->getOrderProduct() instanceof OrderProduct) {
                continue;
            }

            $productId = $this->normalizeId($orderProduct->getProduct()?->getId());
            if (
                $productId !== null &&
                isset($eligibleProductIds[$productId]) &&
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

    /**
     * @return array{enabled:bool,giftProduct:?Product,giftProductId:?int,productIds:array<int,bool>,requiredSales:int}
     */
    private function resolveSettings(People $provider): array
    {
        $enabled = $this->normalizeBoolean(
            $this->configService->getConfig($provider, self::CONFIG_ENABLED)
        );
        $productIds = $this->normalizeIds(
            $this->configService->getConfig($provider, self::CONFIG_PRODUCT_IDS)
        );
        $requiredSales = max(
            0,
            (int) $this->normalizeNumber(
                $this->configService->getConfig($provider, self::CONFIG_REQUIRED_SALES)
            )
        );
        $giftProductId = $this->normalizeId(
            $this->configService->getConfig($provider, self::CONFIG_GIFT_PRODUCT_ID)
        );
        $giftProduct = $giftProductId
            ? $this->manager->getRepository(Product::class)->find($giftProductId)
            : null;

        return [
            'enabled' => $enabled
                && !empty($productIds)
                && $requiredSales > 0
                && $giftProduct instanceof Product,
            'giftProduct' => $giftProduct,
            'giftProductId' => $giftProductId,
            'productIds' => array_fill_keys($productIds, true),
            'requiredSales' => $requiredSales,
        ];
    }

    private function resolveLinkedFidelityCard(Order $order): ?Order
    {
        /*
         * @agents Resolve the canonical card from metadata first, then fall back to the linked main order.
         * This keeps the fidelity chain readable even when another business flow owns the parent link.
         */
        $info = $this->readOrderInfo($order);
        $cardId = $this->normalizeId($info[self::INFO_CARD_ID] ?? null);
        if ($cardId !== null) {
            $card = $this->manager->getRepository(Order::class)->find($cardId);
            if ($card instanceof Order && $this->isFidelityOrder($card)) {
                return $card;
            }
        }

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
