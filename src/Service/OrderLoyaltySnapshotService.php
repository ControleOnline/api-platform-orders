<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use Doctrine\ORM\EntityManagerInterface;

class OrderLoyaltySnapshotService
{
    private const CONFIG_ENABLED = 'shop-loyalty-coupons-enabled';
    private const CONFIG_PRODUCT_IDS = 'shop-loyalty-product-ids';
    private const CONFIG_REQUIRED_SALES = 'shop-loyalty-required-sales';

    private const SNAPSHOT_CARD_LIMIT = 50;
    private const SNAPSHOT_STAMP_LIMIT = 200;

    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly ConfigService $configService,
    ) {
    }

    /**
     * @agents Build the loyalty card snapshot for the current customer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildForClient(
        People $provider,
        People $client,
        bool $showHistory = false,
    ): array {
        /*
         * @agents The snapshot is the canonical source for the shop loyalty screen.
         * The UI can ask for history, but it never rebuilds the card chain from raw orders.
         */
        $settings = $this->resolveSettings($provider);
        if (!$settings['enabled']) {
            return [];
        }

        /*
         * The default snapshot always shows only the most recent open card.
         * When the screen asks for history, we return the full chain for auditability.
         */
        $cards = $this->findCards($provider, $client);
        if ($cards === []) {
            return [];
        }

        if (!$showHistory) {
            $cards = array_values(array_filter(
                $cards,
                fn (Order $card): bool => $this->isOpenCard($card),
            ));

            if ($cards === []) {
                return [];
            }

            $cards = [$cards[0]];
        }

        return array_values(array_map(
            fn (Order $card): array => $this->buildCardSnapshot($card, $settings),
            $cards,
        ));
    }

    /**
     * @return array{enabled:bool,productIds:array<int,bool>,requiredSales:int}
     */
    private function resolveSettings(People $provider): array
    {
        $enabled = $this->normalizeBoolean(
            $this->configService->getConfig($provider, self::CONFIG_ENABLED),
        );
        $productIds = $this->normalizeIds(
            $this->configService->getConfig($provider, self::CONFIG_PRODUCT_IDS),
        );
        $requiredSales = max(
            0,
            (int) $this->normalizeNumber(
                $this->configService->getConfig($provider, self::CONFIG_REQUIRED_SALES),
            ),
        );

        return [
            'enabled' => $enabled && $productIds !== [] && $requiredSales > 0,
            'productIds' => array_fill_keys($productIds, true),
            'requiredSales' => $requiredSales,
        ];
    }

    /**
     * @return Order[]
     */
    private function findCards(People $provider, People $client): array
    {
        $cards = $this->manager->getRepository(Order::class)->findBy(
            [
                'provider' => $provider,
                'client' => $client,
                'orderType' => Order::ORDER_TYPE_FIDELITY,
            ],
            [
                'orderDate' => 'DESC',
                'id' => 'DESC',
            ],
            self::SNAPSHOT_CARD_LIMIT,
        );

        return array_values(array_filter(
            $cards,
            static fn ($card): bool => $card instanceof Order,
        ));
    }

    /**
     * @param array{enabled:bool,productIds:array<int,bool>,requiredSales:int} $settings
     *
     * @return array<string, mixed>
     */
    private function buildCardSnapshot(Order $card, array $settings): array
    {
        $stamps = $this->findEligibleStamps($card, $settings);

        return [
            'card' => $this->normalizeOrderSnapshot($card),
            'requiredSales' => $this->getCardRequiredSales($card, $settings),
            'stamps' => array_values(array_map(
                fn (Order $stamp): array => $this->normalizeOrderSnapshot($stamp),
                $stamps,
            )),
        ];
    }

    /**
     * @param array{enabled:bool,productIds:array<int,bool>,requiredSales:int} $settings
     *
     * @return Order[]
     */
    private function findEligibleStamps(Order $card, array $settings): array
    {
        /*
         * @agents Only closed eligible sales linked to the card count as visible stamps.
         * Reward orders are intentionally excluded so the progress bar stays faithful to the business rule.
         */
        if (!$card->getId()) {
            return [];
        }

        $sales = $this->manager->getRepository(Order::class)->findBy(
            [
                'mainOrderId' => $card->getId(),
                'orderType' => Order::ORDER_TYPE_SALE,
            ],
            [
                'orderDate' => 'ASC',
                'id' => 'ASC',
            ],
            self::SNAPSHOT_STAMP_LIMIT,
        );

        $eligible = [];
        $seen = [];

        foreach ($sales as $sale) {
            if (!$sale instanceof Order) {
                continue;
            }

            if (
                !$this->isSaleLinkedToCard($sale, $card) ||
                !$this->isClosedStampOrder($sale) ||
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
            $eligible[] = $sale;
        }

        return $eligible;
    }

    /**
     * @param array{enabled:bool,productIds:array<int,bool>,requiredSales:int} $settings
     */
    private function getCardRequiredSales(Order $card, array $settings): int
    {
        /*
         * @agents The required sales value can be overridden per card through metadata.
         * When the card has no override, the provider configuration becomes the fallback.
         */
        $info = $this->readOrderInfo($card);
        $requiredSales = (int) ($info['loyalty_required_sales'] ?? 0);

        return max(1, $requiredSales ?: (int) $settings['requiredSales']);
    }

    private function isOpenCard(Order $card): bool
    {
        /*
         * @agents A card is considered open by both persisted status and real status
         * so transitional states still render the current open card correctly.
         */
        $status = $this->normalizeText($card->getStatus()?->getStatus());
        $realStatus = $this->normalizeText($card->getStatus()?->getRealStatus());

        return $status === 'open' || $realStatus === 'open';
    }

    private function isClosedStampOrder(Order $order): bool
    {
        /*
         * @agents Stamps only come from closed sales.
         * Pending or transitional orders stay out of the visible loyalty counter.
         */
        $status = $this->normalizeText($order->getStatus()?->getStatus());
        $realStatus = $this->normalizeText($order->getStatus()?->getRealStatus());

        return $status === 'closed' || $realStatus === 'closed';
    }

    private function isSaleLinkedToCard(Order $sale, Order $card): bool
    {
        /*
         * @agents Legacy cards can still be resolved by metadata when the main order link is shared with another flow.
         */
        $cardId = $this->normalizeId($card->getId());
        if ($cardId === null) {
            return false;
        }

        if ($this->normalizeId($sale->getMainOrderId()) === $cardId) {
            return true;
        }

        $mainOrder = $sale->getMainOrder();
        if ($mainOrder instanceof Order && $this->normalizeId($mainOrder->getId()) === $cardId) {
            return true;
        }

        $info = $this->readOrderInfo($sale);

        return $this->normalizeId($info['loyalty_card_id'] ?? null) === $cardId;
    }

    private function isRewardOrderForCard(Order $sale, Order $card): bool
    {
        /*
         * @agents Reward sales are visible in the card history, but they do not count as stamps.
         */
        $saleId = $this->normalizeId($sale->getId());
        if ($saleId === null) {
            return false;
        }

        $info = $this->readOrderInfo($card);
        $rewardOrderId = $this->normalizeId($info['loyalty_reward_order_id'] ?? null);

        return $rewardOrderId !== null && $rewardOrderId === $saleId;
    }

    /**
     * @param array{enabled:bool,productIds:array<int,bool>,requiredSales:int} $settings
     */
    private function isEligibleSale(Order $sale, array $settings): bool
    {
        /*
         * @agents Eligibility requires a positive root product that belongs to the configured participant list.
         * Gift lines and nested customization items are ignored by design.
         */
        $eligibleProductIds = $settings['productIds'] ?? [];
        if ($eligibleProductIds === []) {
            return false;
        }

        $orderProducts = $sale->getOrderProducts();
        foreach ($orderProducts as $orderProduct) {
            if (
                !$orderProduct instanceof OrderProduct ||
                $orderProduct->getOrderProduct() instanceof OrderProduct
            ) {
                continue;
            }

            $productId = $this->normalizeId($orderProduct->getProduct()?->getId());
            $total = (float) $orderProduct->getTotal();

            if (
                $productId !== null &&
                isset($eligibleProductIds[$productId]) &&
                $total > 0 &&
                !$this->isLoyaltyGiftComment($orderProduct->getComment())
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeOrderSnapshot(Order $order): array
    {
        /*
         * @agents Keep the snapshot intentionally small: the loyalty screen only needs order identity,
         * type, timing, state, amount, comments, and the loyalty metadata block.
         */
        $status = $order->getStatus();
        $otherInformations = $this->readOrderInfo($order);

        return [
            '@id' => $this->normalizeId($order->getId())
                ? sprintf('/orders/%d', (int) $order->getId())
                : null,
            'id' => $this->normalizeId($order->getId()) ?? 0,
            'orderType' => $this->normalizeText($order->getOrderType()),
            'orderDate' => $this->formatDateValue($order->getOrderDate()),
            'status' => [
                'status' => $this->normalizeText($status?->getStatus()),
                'realStatus' => $this->normalizeText($status?->getRealStatus()),
            ],
            'mainOrderId' => $this->normalizeId($order->getMainOrderId()),
            'price' => (float) $order->getPrice(),
            'comments' => trim((string) $order->getComments()),
            'otherInformations' => $otherInformations,
        ];
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

    private function isLoyaltyGiftComment(?string $comment): bool
    {
        return trim((string) $comment) === OrderProductService::LOYALTY_GIFT_COMMENT;
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
        if (!is_numeric($decoded)) {
            return 0;
        }

        return (int) floor((float) $decoded);
    }

    private function normalizeId(mixed $value): ?int
    {
        if (is_object($value) && method_exists($value, 'getId')) {
            $value = $value->getId();
        }

        $normalized = preg_replace('/\D+/', '', (string) $value);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        return (int) $normalized;
    }

    private function normalizeText(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function decodeJsonScalar(mixed $value): mixed
    {
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    private function formatDateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
