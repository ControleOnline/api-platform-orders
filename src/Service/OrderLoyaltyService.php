<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Event\EntityChangedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            EntityChangedEvent::class => 'onEntityChanged',
        ];
    }

    public function onEntityChanged(EntityChangedEvent $event): void
    {
        if (
            $this->handling
            || !in_array($event->getPhase(), ['postPersist', 'postUpdate'], true)
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

        $this->handling = true;
        try {
            if ($this->isCartOrder($order)) {
                $this->applyRewardToCart($order, $settings);
            }

            if ($this->isSaleOrder($order)) {
                $this->processPaidSale($order, $settings);
            }
        } finally {
            $this->handling = false;
        }
    }

    private function applyRewardToCart(Order $cart, array $settings): void
    {
        $giftProduct = $settings['giftProduct'] ?? null;
        if (!$giftProduct instanceof Product) {
            return;
        }

        $card = $this->findRewardableCard($cart, $settings);
        if (!$card instanceof Order) {
            return;
        }

        if (!$this->cartHasLoyaltyGift($cart, $giftProduct)) {
            $this->addGiftProductToCart($cart, $giftProduct);
        }

        $info = $this->readOrderInfo($card);
        $info[self::INFO_REWARD_ORDER_ID] = (int) $cart->getId();
        $info[self::INFO_REWARD_PRODUCT_ID] = (int) $giftProduct->getId();
        $info[self::INFO_REWARD_RESERVED_AT] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->writeOrderInfo($card, $info);

        $this->manager->persist($card);
        $this->manager->flush();
    }

    private function processPaidSale(Order $sale, array $settings): void
    {
        if (!$this->isPaidOrder($sale)) {
            return;
        }

        $this->redeemPendingRewardForSale($sale);

        if (!$this->isEligibleSale($sale, $settings)) {
            return;
        }

        if ($this->resolveLinkedFidelityCard($sale) instanceof Order) {
            return;
        }

        $sale->setOrderType(Order::ORDER_TYPE_SALE);

        $card = $this->findCurrentCard($sale, $settings)
            ?? $this->createCard($sale, $settings);
        if (!$card instanceof Order || !$card->getId()) {
            return;
        }

        $cardId = (int) $card->getId();
        $info = $this->readOrderInfo($sale);
        $info[self::INFO_CARD_ID] = $cardId;
        $this->writeOrderInfo($sale, $info);

        if (!$sale->getMainOrderId()) {
            $sale->setMainOrder($card);
            $sale->setMainOrderId($cardId);
        }

        $this->manager->persist($sale);
        $this->manager->flush();
    }

    private function redeemPendingRewardForSale(Order $sale): void
    {
        $card = $this->findPendingRewardCardForOrder($sale);
        if (!$card instanceof Order) {
            return;
        }

        $info = $this->readOrderInfo($card);
        if (!empty($info[self::INFO_REWARD_REDEEMED_AT])) {
            return;
        }

        $info[self::INFO_REWARD_REDEEMED_AT] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->writeOrderInfo($card, $info);
        $card->setStatus($this->statusService->discoveryStatus('closed', 'redeemed', 'order'));

        $this->manager->persist($card);
        $this->manager->flush();
    }

    private function createCard(Order $sale, array $settings): Order
    {
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

    private function addGiftProductToCart(Order $cart, Product $giftProduct): void
    {
        $orderProduct = new OrderProduct();
        $orderProduct->setOrder($cart);
        $orderProduct->setProduct($giftProduct);
        $orderProduct->setQuantity(1);
        $orderProduct->setPrice(0);
        $orderProduct->setTotal(0);
        $orderProduct->setComment(OrderProductService::LOYALTY_GIFT_COMMENT);
        $cart->addOrderProduct($orderProduct);

        $this->manager->persist($orderProduct);
        $this->manager->flush();
    }

    private function findRewardableCard(Order $cart, array $settings): ?Order
    {
        foreach ($this->findCards($cart) as $card) {
            if (!$this->isOpenCard($card)) {
                continue;
            }

            $info = $this->readOrderInfo($card);
            if (!empty($info[self::INFO_REWARD_REDEEMED_AT])) {
                continue;
            }

            $rewardOrderId = $this->normalizeId($info[self::INFO_REWARD_ORDER_ID] ?? null);
            if ($rewardOrderId !== null && $rewardOrderId !== (int) $cart->getId()) {
                continue;
            }

            if ($this->countCardStamps($card, $settings) >= $this->getCardRequiredSales($card, $settings)) {
                return $card;
            }
        }

        return null;
    }

    private function findCurrentCard(Order $sale, array $settings): ?Order
    {
        foreach ($this->findCards($sale) as $card) {
            if (!$this->isOpenCard($card)) {
                continue;
            }

            $info = $this->readOrderInfo($card);
            if (!empty($info[self::INFO_REWARD_ORDER_ID])) {
                continue;
            }

            if ($this->countCardStamps($card, $settings) < $this->getCardRequiredSales($card, $settings)) {
                return $card;
            }
        }

        return null;
    }

    private function findPendingRewardCardForOrder(Order $order): ?Order
    {
        foreach ($this->findCards($order) as $card) {
            $info = $this->readOrderInfo($card);
            if ($this->normalizeId($info[self::INFO_REWARD_ORDER_ID] ?? null) === (int) $order->getId()) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @return Order[]
     */
    private function findCards(Order $order): array
    {
        $cards = $this->manager->getRepository(Order::class)->findBy(
            [
                'provider' => $order->getProvider(),
                'client' => $order->getClient(),
                'orderType' => Order::ORDER_TYPE_FIDELITY,
            ],
            ['orderDate' => 'DESC'],
            20,
        );

        return array_values(array_filter($cards, fn($card) => $card instanceof Order));
    }

    private function resolveLinkedFidelityCard(Order $order): ?Order
    {
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

    private function countCardStamps(Order $card, array $settings): int
    {
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
                ['orderDate' => 'DESC'],
                200,
            ),
        );

        $count = 0;
        $seen = [];
        foreach ($sales as $sale) {
            if (
                !$sale instanceof Order
                || !$this->isPaidOrder($sale)
                || !$this->isSaleLinkedToCard($sale, $card)
                || !$this->isEligibleSale($sale, $settings)
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

    private function cartHasLoyaltyGift(Order $cart, Product $giftProduct): bool
    {
        foreach ($cart->getOrderProducts() as $orderProduct) {
            if (
                $orderProduct instanceof OrderProduct
                && $orderProduct->getProduct()?->getId() === $giftProduct->getId()
                && OrderProductService::isLoyaltyGiftComment($orderProduct->getComment())
            ) {
                return true;
            }
        }

        return false;
    }

    private function isEligibleSale(Order $sale, array $settings): bool
    {
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
                $productId !== null
                && isset($eligibleProductIds[$productId])
                && (float) $orderProduct->getTotal() > 0
                && !OrderProductService::isLoyaltyGiftComment($orderProduct->getComment())
            ) {
                return true;
            }
        }

        return false;
    }

    private function isPaidOrder(Order $order): bool
    {
        $status = $this->normalizeText($order->getStatus()?->getStatus());
        $realStatus = $this->normalizeText($order->getStatus()?->getRealStatus());
        if ($status === 'paid' || $realStatus === 'paid') {
            return true;
        }

        $price = (float) $order->getPrice();
        if ($price <= 0) {
            return false;
        }

        $paidValue = 0.0;
        foreach ($order->getInvoice() as $orderInvoice) {
            $invoice = $orderInvoice->getInvoice();
            if ($this->normalizeText($invoice?->getStatus()?->getRealStatus()) === 'closed') {
                $paidValue += (float) $invoice->getPrice();
            }
        }

        return $paidValue > 0 && $paidValue + 0.0001 >= $price;
    }

    private function getCardRequiredSales(Order $card, array $settings): int
    {
        $info = $this->readOrderInfo($card);
        $requiredSales = (int) ($info[self::INFO_REQUIRED_SALES] ?? 0);

        return max(1, $requiredSales ?: (int) $settings['requiredSales']);
    }

    private function isOpenCard(Order $card): bool
    {
        return $this->isFidelityOrder($card)
            && $this->normalizeText($card->getStatus()?->getRealStatus()) === 'open';
    }

    private function isFidelityOrder(Order $order): bool
    {
        return $this->normalizeText($order->getOrderType()) === Order::ORDER_TYPE_FIDELITY;
    }

    private function isSaleOrder(Order $order): bool
    {
        return $this->normalizeText($order->getOrderType()) === Order::ORDER_TYPE_SALE;
    }

    private function isCartOrder(Order $order): bool
    {
        return $this->normalizeText($order->getOrderType()) === Order::ORDER_TYPE_CART;
    }

    private function resolveSettings(People $provider): array
    {
        $enabled = $this->normalizeBoolean(
            $this->configService->getConfig($provider, self::CONFIG_ENABLED)
        );
        $productIds = $this->normalizeIds(
            $this->configService->getConfig($provider, self::CONFIG_PRODUCT_IDS)
        );
        $requiredSales = max(0, (int) $this->normalizeNumber(
            $this->configService->getConfig($provider, self::CONFIG_REQUIRED_SALES)
        ));
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

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = $this->normalizeText($this->decodeJsonScalar($value));

        return in_array($normalized, ['1', 'true', 'yes', 'sim', 'on', 'enabled', 'ativo'], true);
    }

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
}
