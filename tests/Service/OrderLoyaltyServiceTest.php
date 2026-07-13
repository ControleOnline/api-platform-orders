<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\Status;
use ControleOnline\Event\EntityChangedEvent;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\OrderLoyaltyService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class OrderLoyaltyServiceTest extends TestCase
{
    public function testNonEligiblePaidSaleDoesNotCreateFidelityCard(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $nonEligibleProduct = $this->product(99, 25.0);
        $sale = $this->order(100, Order::ORDER_TYPE_SALE, $provider, $client, $this->orderStatus('paid', 'paid'));
        $sale->setPrice(25.0);
        $sale->addOrderProduct($this->orderProduct($sale, $nonEligibleProduct, 25.0));

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(fn(array $criteria) => match ($criteria['orderType'] ?? null) {
                Order::ORDER_TYPE_FIDELITY => [],
                default => [],
            });

        $productRepository = $this->createMock(EntityRepository::class);
        $productRepository
            ->method('find')
            ->with(40)
            ->willReturn($this->product(40, 12.0));

        $manager = $this->manager([
            Order::class => $orderRepository,
            Product::class => $productRepository,
        ]);
        $manager->expects(self::never())->method('persist');

        $service = new OrderLoyaltyService(
            $manager,
            $this->configService('[30]', '3', '40'),
            $this->statusService(),
        );

        $service->onEntityChanged(new EntityChangedEvent($sale, 'postUpdate'));

        self::assertSame(Order::ORDER_TYPE_SALE, $sale->getOrderType());
        self::assertNull($sale->getMainOrderId());
        self::assertSame('', (string) $sale->getOtherInformations());
    }

    public function testPaidSaleCreatesFidelityCardAndLinksSale(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $giftProduct = $this->product(40, 12.0);
        $sale = $this->order(100, Order::ORDER_TYPE_SALE, $provider, $client, $this->orderStatus('paid', 'paid'));
        $sale->setPrice(25.0);
        $sale->addOrderProduct($this->orderProduct($sale, $eligibleProduct, 25.0));

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(fn(array $criteria) => match ($criteria['orderType'] ?? null) {
                Order::ORDER_TYPE_FIDELITY => [],
                default => [],
            });

        $productRepository = $this->createMock(EntityRepository::class);
        $productRepository
            ->method('find')
            ->with(40)
            ->willReturn($giftProduct);

        $manager = $this->manager([
            Order::class => $orderRepository,
            Product::class => $productRepository,
        ]);
        $createdCard = null;
        $manager
            ->expects(self::atLeastOnce())
            ->method('persist')
            ->willReturnCallback(function (object $entity) use (&$createdCard): void {
                if ($entity instanceof Order && $entity->getOrderType() === Order::ORDER_TYPE_FIDELITY) {
                    $this->setEntityId($entity, 500);
                    $createdCard = $entity;
                }
            });

        $service = new OrderLoyaltyService(
            $manager,
            $this->configService('[30]', '3', '40'),
            $this->statusService(),
        );

        $service->onEntityChanged(new EntityChangedEvent($sale, 'postUpdate'));

        self::assertInstanceOf(Order::class, $createdCard);
        self::assertSame(Order::ORDER_TYPE_FIDELITY, $createdCard->getOrderType());
        self::assertSame(Order::ORDER_TYPE_SALE, $sale->getOrderType());
        self::assertSame(500, $sale->getMainOrderId());
        self::assertSame($createdCard, $sale->getMainOrder());
        self::assertSame(
            500,
            json_decode((string) $sale->getOtherInformations(), true)['loyalty_card_id'] ?? null
        );
    }

    public function testPaidChildSaleKeepsCommercialParentAndStoresLoyaltyCard(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $giftProduct = $this->product(40, 12.0);
        $paidStatus = $this->orderStatus('paid', 'paid');
        $parentSale = $this->order(99, Order::ORDER_TYPE_SALE, $provider, $client, $paidStatus);
        $sale = $this->order(100, Order::ORDER_TYPE_SALE, $provider, $client, $paidStatus);
        $sale->setPrice(25.0);
        $sale->setMainOrder($parentSale);
        $sale->setMainOrderId(99);
        $sale->addOrderProduct($this->orderProduct($sale, $eligibleProduct, 25.0));

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(fn(array $criteria) => match ($criteria['orderType'] ?? null) {
                Order::ORDER_TYPE_FIDELITY => [],
                Order::ORDER_TYPE_SALE => [],
                default => [],
            });
        $orderRepository
            ->method('find')
            ->with(500)
            ->willReturn(null);

        $productRepository = $this->createMock(EntityRepository::class);
        $productRepository
            ->method('find')
            ->with(40)
            ->willReturn($giftProduct);

        $manager = $this->manager([
            Order::class => $orderRepository,
            Product::class => $productRepository,
        ]);
        $manager
            ->expects(self::atLeastOnce())
            ->method('persist')
            ->willReturnCallback(function (object $entity): void {
                if ($entity instanceof Order && $entity->getOrderType() === Order::ORDER_TYPE_FIDELITY) {
                    $this->setEntityId($entity, 500);
                }
            });

        $service = new OrderLoyaltyService(
            $manager,
            $this->configService('[30]', '3', '40'),
            $this->statusService(),
        );

        $service->onEntityChanged(new EntityChangedEvent($sale, 'postUpdate'));

        self::assertSame(Order::ORDER_TYPE_SALE, $sale->getOrderType());
        self::assertSame(99, $sale->getMainOrderId());
        self::assertSame($parentSale, $sale->getMainOrder());
        self::assertSame(
            500,
            json_decode((string) $sale->getOtherInformations(), true)['loyalty_card_id'] ?? null
        );
    }

    public function testFullFidelityCardAddsFreeGiftToNextCart(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $giftProduct = $this->product(40, 12.0);
        $openStatus = $this->orderStatus('open', 'open');
        $paidStatus = $this->orderStatus('paid', 'paid');
        $card = $this->order(500, Order::ORDER_TYPE_FIDELITY, $provider, $client, $openStatus);
        $card->setOtherInformations((object) ['loyalty_required_sales' => 2]);
        $firstSale = $this->order(101, Order::ORDER_TYPE_SALE, $provider, $client, $paidStatus);
        $secondSale = $this->order(102, Order::ORDER_TYPE_SALE, $provider, $client, $paidStatus);
        $firstSale->setMainOrderId(500);
        $secondSale->setMainOrderId(500);
        $firstSale->addOrderProduct($this->orderProduct($firstSale, $eligibleProduct, 25.0));
        $secondSale->addOrderProduct($this->orderProduct($secondSale, $eligibleProduct, 25.0));
        $cart = $this->order(200, Order::ORDER_TYPE_CART, $provider, $client, $openStatus);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use ($card, $firstSale, $secondSale): array {
                if (($criteria['orderType'] ?? null) === Order::ORDER_TYPE_FIDELITY) {
                    return [$card];
                }

                if (($criteria['mainOrderId'] ?? null) === 500) {
                    return [$firstSale, $secondSale];
                }

                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_SALE
                    && ($criteria['provider'] ?? null) === $provider
                    && ($criteria['client'] ?? null) === $client
                ) {
                    return [$firstSale, $secondSale];
                }

                return [];
            });

        $productRepository = $this->createMock(EntityRepository::class);
        $productRepository
            ->method('find')
            ->with(40)
            ->willReturn($giftProduct);

        $manager = $this->manager([
            Order::class => $orderRepository,
            Product::class => $productRepository,
        ]);

        $service = new OrderLoyaltyService(
            $manager,
            $this->configService('[30]', '2', '40'),
            $this->statusService(),
        );

        $service->onEntityChanged(new EntityChangedEvent($cart, 'postPersist'));

        $giftItems = array_values(array_filter(
            $cart->getOrderProducts()->toArray(),
            fn($item) => $item instanceof OrderProduct
                && $item->getProduct() === $giftProduct
                && $item->getPrice() === 0
                && $item->getTotal() === 0
                && $item->getComment() === OrderProductService::LOYALTY_GIFT_COMMENT
        ));
        $cardInfo = json_decode($card->getOtherInformations(), true);

        self::assertCount(1, $giftItems);
        self::assertSame(200, $cardInfo['loyalty_reward_order_id']);
        self::assertSame(40, $cardInfo['loyalty_reward_product_id']);
    }

    public function testIneligiblePaidSaleDoesNotCountTowardRewardCard(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $nonEligibleProduct = $this->product(99, 25.0);
        $giftProduct = $this->product(40, 12.0);
        $openStatus = $this->orderStatus('open', 'open');
        $paidStatus = $this->orderStatus('paid', 'paid');
        $card = $this->order(500, Order::ORDER_TYPE_FIDELITY, $provider, $client, $openStatus);
        $card->setOtherInformations((object) ['loyalty_required_sales' => 2]);
        $eligibleSale = $this->order(101, Order::ORDER_TYPE_SALE, $provider, $client, $paidStatus);
        $ineligibleSale = $this->order(102, Order::ORDER_TYPE_SALE, $provider, $client, $paidStatus);
        $eligibleSale->setMainOrderId(500);
        $ineligibleSale->setMainOrderId(500);
        $eligibleSale->addOrderProduct($this->orderProduct($eligibleSale, $eligibleProduct, 25.0));
        $ineligibleSale->addOrderProduct($this->orderProduct($ineligibleSale, $nonEligibleProduct, 25.0));
        $cart = $this->order(200, Order::ORDER_TYPE_CART, $provider, $client, $openStatus);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use ($card, $eligibleSale, $ineligibleSale, $provider, $client): array {
                if (($criteria['orderType'] ?? null) === Order::ORDER_TYPE_FIDELITY) {
                    return [$card];
                }

                if (($criteria['mainOrderId'] ?? null) === 500) {
                    return [$eligibleSale, $ineligibleSale];
                }

                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_SALE
                    && ($criteria['provider'] ?? null) === $provider
                    && ($criteria['client'] ?? null) === $client
                ) {
                    return [$eligibleSale, $ineligibleSale];
                }

                return [];
            });

        $productRepository = $this->createMock(EntityRepository::class);
        $productRepository
            ->method('find')
            ->with(40)
            ->willReturn($giftProduct);

        $manager = $this->manager([
            Order::class => $orderRepository,
            Product::class => $productRepository,
        ]);

        $service = new OrderLoyaltyService(
            $manager,
            $this->configService('[30]', '2', '40'),
            $this->statusService(),
        );

        $service->onEntityChanged(new EntityChangedEvent($cart, 'postPersist'));

        $giftItems = array_values(array_filter(
            $cart->getOrderProducts()->toArray(),
            fn($item) => $item instanceof OrderProduct
                && $item->getProduct() === $giftProduct
                && $item->getComment() === OrderProductService::LOYALTY_GIFT_COMMENT
        ));

        self::assertCount(0, $giftItems);
    }

    public function testFullFidelityCardCountsSalesLinkedByLoyaltyMetadata(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $giftProduct = $this->product(40, 12.0);
        $openStatus = $this->orderStatus('open', 'open');
        $paidStatus = $this->orderStatus('paid', 'paid');
        $card = $this->order(500, Order::ORDER_TYPE_FIDELITY, $provider, $client, $openStatus);
        $card->setOtherInformations((object) ['loyalty_required_sales' => 2]);
        $firstSale = $this->order(101, Order::ORDER_TYPE_SALE, $provider, $client, $paidStatus);
        $secondSale = $this->order(102, Order::ORDER_TYPE_SALE, $provider, $client, $paidStatus);
        $firstSale->setMainOrderId(900);
        $secondSale->setMainOrderId(901);
        $firstSale->setOtherInformations((object) ['loyalty_card_id' => 500]);
        $secondSale->setOtherInformations((object) ['loyalty_card_id' => 500]);
        $firstSale->addOrderProduct($this->orderProduct($firstSale, $eligibleProduct, 25.0));
        $secondSale->addOrderProduct($this->orderProduct($secondSale, $eligibleProduct, 25.0));
        $cart = $this->order(200, Order::ORDER_TYPE_CART, $provider, $client, $openStatus);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use ($card, $firstSale, $secondSale, $provider, $client): array {
                if (($criteria['orderType'] ?? null) === Order::ORDER_TYPE_FIDELITY) {
                    return [$card];
                }

                if (($criteria['mainOrderId'] ?? null) === 500) {
                    return [];
                }

                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_SALE
                    && ($criteria['provider'] ?? null) === $provider
                    && ($criteria['client'] ?? null) === $client
                ) {
                    return [$firstSale, $secondSale];
                }

                return [];
            });
        $orderRepository
            ->method('find')
            ->with(500)
            ->willReturn($card);

        $productRepository = $this->createMock(EntityRepository::class);
        $productRepository
            ->method('find')
            ->with(40)
            ->willReturn($giftProduct);

        $manager = $this->manager([
            Order::class => $orderRepository,
            Product::class => $productRepository,
        ]);

        $service = new OrderLoyaltyService(
            $manager,
            $this->configService('[30]', '2', '40'),
            $this->statusService(),
        );

        $service->onEntityChanged(new EntityChangedEvent($cart, 'postPersist'));

        $giftItems = array_values(array_filter(
            $cart->getOrderProducts()->toArray(),
            fn($item) => $item instanceof OrderProduct
                && $item->getProduct() === $giftProduct
                && $item->getComment() === OrderProductService::LOYALTY_GIFT_COMMENT
        ));

        self::assertCount(1, $giftItems);
    }

    private function configService(string $productIds, string $requiredSales, string $giftProductId): ConfigService
    {
        $configService = $this->createMock(ConfigService::class);
        $configService
            ->method('getConfig')
            ->willReturnCallback(
                fn(People $people, string $key) => match ($key) {
                    'shop-loyalty-coupons-enabled' => '1',
                    'shop-loyalty-product-ids' => $productIds,
                    'shop-loyalty-required-sales' => $requiredSales,
                    'shop-loyalty-gift-product-id' => $giftProductId,
                    default => null,
                }
            );

        return $configService;
    }

    private function statusService(): StatusService
    {
        $statusService = $this->createMock(StatusService::class);
        $statusService
            ->method('discoveryStatus')
            ->willReturnCallback(
                fn(string $realStatus, string $status, string $context) => $this->orderStatus($status, $realStatus)
            );

        return $statusService;
    }

    /**
     * @param array<class-string, EntityRepository> $repositories
     */
    private function manager(array $repositories): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->method('getRepository')
            ->willReturnCallback(fn(string $class) => $repositories[$class]);
        $manager
            ->method('flush');

        return $manager;
    }

    private function order(
        int $id,
        string $orderType,
        People $provider,
        People $client,
        Status $status
    ): Order {
        $order = new Order();
        $this->setEntityId($order, $id);
        $order->setOrderType($orderType);
        $order->setProvider($provider);
        $order->setClient($client);
        $order->setPayer($client);
        $order->setStatus($status);
        $order->setApp('SHOP');

        return $order;
    }

    private function orderProduct(Order $order, Product $product, float $total): OrderProduct
    {
        $orderProduct = new OrderProduct();
        $orderProduct->setOrder($order);
        $orderProduct->setProduct($product);
        $orderProduct->setQuantity(1);
        $orderProduct->setPrice($total);
        $orderProduct->setTotal($total);

        return $orderProduct;
    }

    private function product(int $id, float $price): Product
    {
        $product = new Product();
        $product->setId($id);
        $product->setProduct(sprintf('Produto %d', $id));
        $product->setPrice($price);

        return $product;
    }

    private function people(int $id): People
    {
        $people = new People();
        $this->setEntityId($people, $id);

        return $people;
    }

    private function orderStatus(string $status, string $realStatus): Status
    {
        $entityStatus = new Status();
        $entityStatus->setStatus($status);
        $entityStatus->setRealStatus($realStatus);
        $entityStatus->setContext('order');

        return $entityStatus;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionObject($entity);
        while ($reflection) {
            if ($reflection->hasProperty('id')) {
                $property = $reflection->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($entity, $id);
                return;
            }

            $reflection = $reflection->getParentClass();
        }
    }
}
