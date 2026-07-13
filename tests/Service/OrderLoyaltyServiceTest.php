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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class OrderLoyaltyServiceTest extends TestCase
{
    public function testClosedNonEligibleSaleDoesNothing(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $nonEligibleProduct = $this->product(99, 25.0);
        $sale = $this->order(100, Order::ORDER_TYPE_SALE, $provider, $client, $this->orderStatus('closed', 'closed'));
        $sale->setPrice(25.0);
        $sale->addOrderProduct($this->orderProduct($sale, $nonEligibleProduct, 25.0));

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturn([]);

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

        self::assertNull($sale->getMainOrderId());
        self::assertSame('{}', (string) $sale->getOtherInformations());
    }

    public function testClosedEligibleSaleCreatesOpenFidelityCardAndLinksSale(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $giftProduct = $this->product(40, 12.0);
        $sale = $this->order(100, Order::ORDER_TYPE_SALE, $provider, $client, $this->orderStatus('closed', 'closed'));
        $sale->setPrice(25.0);
        $sale->addOrderProduct($this->orderProduct($sale, $eligibleProduct, 25.0));

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturn([]);

        $productRepository = $this->createMock(EntityRepository::class);
        $productRepository
            ->method('find')
            ->with(40)
            ->willReturn($giftProduct);

        $createdCard = null;
        $manager = $this->manager([
            Order::class => $orderRepository,
            Product::class => $productRepository,
        ]);
        $manager
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
        self::assertSame('open', $createdCard->getStatus()->getRealStatus());

        $cardInfo = json_decode((string) $createdCard->getOtherInformations(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(3, $cardInfo['loyalty_required_sales']);
        self::assertSame(40, $cardInfo['loyalty_gift_product_id']);

        self::assertSame(500, $sale->getMainOrderId());
        self::assertSame($createdCard, $sale->getMainOrder());
        self::assertSame('{}', (string) $sale->getOtherInformations());
    }

    public function testClosedEligibleSaleStampsLatestOpenFidelityCard(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $giftProduct = $this->product(40, 12.0);
        $openStatus = $this->orderStatus('open', 'open');
        $closedStatus = $this->orderStatus('closed', 'closed');

        $card = $this->order(500, Order::ORDER_TYPE_FIDELITY, $provider, $client, $openStatus);
        $card->setOtherInformations((object) [
            'loyalty_required_sales' => 3,
            'loyalty_gift_product_id' => 40,
        ]);

        $existingSale = $this->order(401, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $existingSale->setMainOrderId(500);
        $existingSale->addOrderProduct($this->orderProduct($existingSale, $eligibleProduct, 25.0));

        $sale = $this->order(402, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $sale->setPrice(25.0);
        $sale->addOrderProduct($this->orderProduct($sale, $eligibleProduct, 25.0));

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use ($card, $existingSale, $provider, $client): array {
                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_FIDELITY &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$card];
                }

                if (($criteria['mainOrderId'] ?? null) === 500) {
                    return [$existingSale];
                }

                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_SALE &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$existingSale];
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
            $this->configService('[30]', '3', '40'),
            $this->statusService(),
        );

        $service->onEntityChanged(new EntityChangedEvent($sale, 'postUpdate'));

        self::assertSame(500, $sale->getMainOrderId());
        self::assertSame($card, $sale->getMainOrder());
        self::assertSame('open', $card->getStatus()->getRealStatus());
        $cardInfo = json_decode((string) $card->getOtherInformations(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('loyalty_reward_order_id', $cardInfo);
        self::assertSame('{}', (string) $sale->getOtherInformations());
    }

    public function testClosedEligibleSaleStartsNewCardWhenTheCurrentOneIsFullWithoutGift(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $giftProduct = $this->product(40, 12.0);
        $openStatus = $this->orderStatus('open', 'open');
        $closedStatus = $this->orderStatus('closed', 'closed');

        $card = $this->order(500, Order::ORDER_TYPE_FIDELITY, $provider, $client, $openStatus);
        $card->setOtherInformations((object) [
            'loyalty_required_sales' => 3,
            'loyalty_gift_product_id' => 40,
        ]);

        $firstSale = $this->order(401, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $secondSale = $this->order(402, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $thirdSale = $this->order(403, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        foreach ([$firstSale, $secondSale, $thirdSale] as $stampSale) {
            $stampSale->setMainOrderId(500);
            $stampSale->addOrderProduct($this->orderProduct($stampSale, $eligibleProduct, 25.0));
        }

        $sale = $this->order(404, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $sale->setPrice(25.0);
        $sale->addOrderProduct($this->orderProduct($sale, $eligibleProduct, 25.0));

        $createdCard = null;
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use (
                $card,
                $firstSale,
                $secondSale,
                $thirdSale,
                $provider,
                $client,
            ): array {
                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_FIDELITY &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$card];
                }

                if (($criteria['mainOrderId'] ?? null) === 500) {
                    return [$firstSale, $secondSale, $thirdSale];
                }

                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_SALE &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$firstSale, $secondSale, $thirdSale];
                }

                return [];
            });
        $orderRepository
            ->method('find')
            ->willReturnCallback(function (int $id) use ($card): ?Order {
                return $id === 500 ? $card : null;
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
        $manager
            ->method('persist')
            ->willReturnCallback(function (object $entity) use (&$createdCard): void {
                if ($entity instanceof Order && $entity->getOrderType() === Order::ORDER_TYPE_FIDELITY && !$entity->getId()) {
                    $this->setEntityId($entity, 600);
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
        self::assertSame(600, $sale->getMainOrderId());
        self::assertSame($createdCard, $sale->getMainOrder());
        self::assertSame('open', $createdCard->getStatus()->getRealStatus());
        self::assertSame('open', $card->getStatus()->getRealStatus());
    }

    public function testClosedEligibleSaleWithGiftClosesLatestFullCard(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $giftProduct = $this->product(40, 12.0);
        $openStatus = $this->orderStatus('open', 'open');
        $closedStatus = $this->orderStatus('closed', 'closed');

        $card = $this->order(500, Order::ORDER_TYPE_FIDELITY, $provider, $client, $openStatus);
        $card->setOtherInformations((object) [
            'loyalty_required_sales' => 3,
            'loyalty_gift_product_id' => 40,
        ]);

        $firstSale = $this->order(401, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $secondSale = $this->order(402, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $thirdSale = $this->order(403, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        foreach ([$firstSale, $secondSale, $thirdSale] as $stampSale) {
            $stampSale->setMainOrderId(500);
            $stampSale->addOrderProduct($this->orderProduct($stampSale, $eligibleProduct, 25.0));
        }

        $sale = $this->order(404, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $sale->setPrice(25.0);
        $sale->addOrderProduct($this->orderProduct($sale, $eligibleProduct, 25.0));
        $sale->addOrderProduct($this->orderProduct($sale, $giftProduct, 0.0, OrderProductService::LOYALTY_GIFT_COMMENT));

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use (
                $card,
                $firstSale,
                $secondSale,
                $thirdSale,
                $provider,
                $client,
            ): array {
                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_FIDELITY &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$card];
                }

                if (($criteria['mainOrderId'] ?? null) === 500) {
                    return [$firstSale, $secondSale, $thirdSale];
                }

                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_SALE &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$firstSale, $secondSale, $thirdSale];
                }

                return [];
            });
        $orderRepository
            ->method('find')
            ->willReturnCallback(function (int $id) use ($card): ?Order {
                return $id === 500 ? $card : null;
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
            $this->configService('[30]', '3', '40'),
            $this->statusService(),
        );

        $service->onEntityChanged(new EntityChangedEvent($sale, 'postUpdate'));

        self::assertSame(500, $sale->getMainOrderId());
        self::assertSame($card, $sale->getMainOrder());
        self::assertSame('closed', $card->getStatus()->getRealStatus());

        $cardInfo = json_decode((string) $card->getOtherInformations(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(404, $cardInfo['loyalty_reward_order_id']);
        self::assertSame(40, $cardInfo['loyalty_reward_product_id']);
        self::assertNotEmpty($cardInfo['loyalty_reward_redeemed_at']);
    }

    public function testClosedGiftOnlySaleClosesLatestFullCard(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $giftProduct = $this->product(40, 12.0);
        $openStatus = $this->orderStatus('open', 'open');
        $closedStatus = $this->orderStatus('closed', 'closed');

        $card = $this->order(500, Order::ORDER_TYPE_FIDELITY, $provider, $client, $openStatus);
        $card->setOtherInformations((object) [
            'loyalty_required_sales' => 3,
            'loyalty_gift_product_id' => 40,
        ]);

        $firstSale = $this->order(401, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $secondSale = $this->order(402, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $thirdSale = $this->order(403, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        foreach ([$firstSale, $secondSale, $thirdSale] as $stampSale) {
            $stampSale->setMainOrderId(500);
            $stampSale->addOrderProduct($this->orderProduct($stampSale, $eligibleProduct, 25.0));
        }

        $sale = $this->order(404, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $sale->setPrice(0);
        $sale->addOrderProduct($this->orderProduct($sale, $giftProduct, 0.0, OrderProductService::LOYALTY_GIFT_COMMENT));

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use (
                $card,
                $firstSale,
                $secondSale,
                $thirdSale,
                $provider,
                $client,
            ): array {
                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_FIDELITY &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$card];
                }

                if (($criteria['mainOrderId'] ?? null) === 500) {
                    return [$firstSale, $secondSale, $thirdSale];
                }

                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_SALE &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$firstSale, $secondSale, $thirdSale];
                }

                return [];
            });
        $orderRepository
            ->method('find')
            ->willReturnCallback(function (int $id) use ($card): ?Order {
                return $id === 500 ? $card : null;
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
            $this->configService('[30]', '3', '40'),
            $this->statusService(),
        );

        $service->onEntityChanged(new EntityChangedEvent($sale, 'postUpdate'));

        self::assertSame(500, $sale->getMainOrderId());
        self::assertSame($card, $sale->getMainOrder());
        self::assertSame('closed', $card->getStatus()->getRealStatus());

        $cardInfo = json_decode((string) $card->getOtherInformations(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(404, $cardInfo['loyalty_reward_order_id']);
        self::assertSame(40, $cardInfo['loyalty_reward_product_id']);
        self::assertNotEmpty($cardInfo['loyalty_reward_redeemed_at']);
    }

    public function testClosedEligibleSaleOverridesCommercialParentLinkWithFidelityCard(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $giftProduct = $this->product(40, 12.0);
        $openStatus = $this->orderStatus('open', 'open');
        $closedStatus = $this->orderStatus('closed', 'closed');

        $parentSale = $this->order(99, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $card = $this->order(500, Order::ORDER_TYPE_FIDELITY, $provider, $client, $openStatus);
        $card->setOtherInformations((object) [
            'loyalty_required_sales' => 3,
            'loyalty_gift_product_id' => 40,
        ]);

        $stampSale = $this->order(401, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $stampSale->setMainOrderId(500);
        $stampSale->addOrderProduct($this->orderProduct($stampSale, $eligibleProduct, 25.0));

        $sale = $this->order(100, Order::ORDER_TYPE_SALE, $provider, $client, $closedStatus);
        $sale->setMainOrder($parentSale);
        $sale->setMainOrderId(99);
        $sale->setPrice(25.0);
        $sale->addOrderProduct($this->orderProduct($sale, $eligibleProduct, 25.0));

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use ($card, $stampSale, $provider, $client): array {
                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_FIDELITY &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$card];
                }

                if (($criteria['mainOrderId'] ?? null) === 500) {
                    return [$stampSale];
                }

                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_SALE &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$stampSale];
                }

                return [];
            });
        $orderRepository
            ->method('find')
            ->willReturnCallback(function (int $id) use ($card, $parentSale): ?Order {
                return match ($id) {
                    500 => $card,
                    99 => $parentSale,
                    default => null,
                };
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
            $this->configService('[30]', '3', '40'),
            $this->statusService(),
        );

        $service->onEntityChanged(new EntityChangedEvent($sale, 'postUpdate'));

        self::assertSame(500, $sale->getMainOrderId());
        self::assertSame($card, $sale->getMainOrder());
        self::assertSame('{}', (string) $sale->getOtherInformations());
    }

    private function configService(string $productIds, string $requiredSales, string $giftProductId): ConfigService
    {
        $configService = $this->createMock(ConfigService::class);
        $configService
            ->method('getConfig')
            ->willReturnCallback(
                fn (People $people, string $key) => match ($key) {
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
                fn (string $realStatus, string $status, string $context) => $this->orderStatus($status, $realStatus)
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
            ->willReturnCallback(fn (string $class) => $repositories[$class] ?? $this->createMock(EntityRepository::class));

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

    private function orderProduct(Order $order, Product $product, float $total, ?string $comment = null): OrderProduct
    {
        $orderProduct = new OrderProduct();
        $orderProduct->setOrder($order);
        $orderProduct->setProduct($product);
        $orderProduct->setQuantity(1);
        $orderProduct->setPrice($total);
        $orderProduct->setTotal($total);
        if ($comment !== null) {
            $orderProduct->setComment($comment);
        }

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
