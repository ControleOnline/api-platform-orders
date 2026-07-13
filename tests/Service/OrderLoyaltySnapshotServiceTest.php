<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\Status;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\OrderLoyaltySnapshotService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class OrderLoyaltySnapshotServiceTest extends TestCase
{
    public function testBuildForClientReturnsLatestOpenCardWithClosedEligibleStampsOnly(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);
        $otherProduct = $this->product(99, 25.0);

        $latestOpenCard = $this->order(
            600,
            Order::ORDER_TYPE_FIDELITY,
            $provider,
            $client,
            $this->orderStatus('open', 'open'),
            '2026-07-12T10:00:00Z',
        );
        $latestOpenCard->setOtherInformations((object) ['loyalty_required_sales' => 4]);

        $olderOpenCard = $this->order(
            500,
            Order::ORDER_TYPE_FIDELITY,
            $provider,
            $client,
            $this->orderStatus('open', 'open'),
            '2026-07-11T10:00:00Z',
        );

        $closedChild = $this->order(
            701,
            Order::ORDER_TYPE_SALE,
            $provider,
            $client,
            $this->orderStatus('closed', 'closed'),
            '2026-07-12T11:00:00Z',
        );
        $closedChild->setMainOrderId(600);
        $closedChild->addOrderProduct($this->orderProduct($closedChild, $eligibleProduct, 25.0));

        $ineligibleChild = $this->order(
            702,
            Order::ORDER_TYPE_SALE,
            $provider,
            $client,
            $this->orderStatus('closed', 'closed'),
            '2026-07-12T12:00:00Z',
        );
        $ineligibleChild->setMainOrderId(600);
        $ineligibleChild->addOrderProduct($this->orderProduct($ineligibleChild, $otherProduct, 25.0));

        $paidChild = $this->order(
            703,
            Order::ORDER_TYPE_SALE,
            $provider,
            $client,
            $this->orderStatus('paid', 'paid'),
            '2026-07-12T13:00:00Z',
        );
        $paidChild->setMainOrderId(600);
        $paidChild->addOrderProduct($this->orderProduct($paidChild, $eligibleProduct, 25.0));

        $olderCardChild = $this->order(
            801,
            Order::ORDER_TYPE_SALE,
            $provider,
            $client,
            $this->orderStatus('closed', 'closed'),
            '2026-07-11T11:00:00Z',
        );
        $olderCardChild->setMainOrderId(500);
        $olderCardChild->addOrderProduct($this->orderProduct($olderCardChild, $eligibleProduct, 25.0));

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(function (
                array $criteria,
                array $orderBy = [],
                $limit = null,
                $offset = null,
            ) use (
                $latestOpenCard,
                $olderOpenCard,
                $closedChild,
                $ineligibleChild,
                $paidChild,
                $olderCardChild,
                $provider,
                $client,
            ): array {
                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_FIDELITY &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$latestOpenCard, $olderOpenCard];
                }

                if (($criteria['mainOrderId'] ?? null) === 600) {
                    return [$closedChild, $ineligibleChild, $paidChild];
                }

                if (($criteria['mainOrderId'] ?? null) === 500) {
                    return [$olderCardChild];
                }

                return [];
            });

        $manager = $this->manager([Order::class => $orderRepository]);
        $service = new OrderLoyaltySnapshotService($manager, $this->configService());

        $cards = $service->buildForClient($provider, $client);

        self::assertCount(1, $cards);
        self::assertSame(600, $cards[0]['card']['id']);
        self::assertSame(4, $cards[0]['requiredSales']);
        self::assertSame([701], array_map(
            static fn (array $stamp): int => (int) $stamp['id'],
            $cards[0]['stamps'],
        ));
    }

    public function testBuildForClientReturnsEmptyWhenNoOpenCardExists(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);

        $closedCard = $this->order(
            600,
            Order::ORDER_TYPE_FIDELITY,
            $provider,
            $client,
            $this->orderStatus('closed', 'closed'),
            '2026-07-12T10:00:00Z',
        );

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(function (
                array $criteria,
                array $orderBy = [],
                $limit = null,
                $offset = null,
            ) use ($closedCard, $provider, $client): array {
                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_FIDELITY &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$closedCard];
                }

                return [];
            });

        $manager = $this->manager([Order::class => $orderRepository]);
        $service = new OrderLoyaltySnapshotService($manager, $this->configService());

        self::assertSame([], $service->buildForClient($provider, $client));
    }

    public function testBuildForClientCanReturnHistoryCardsWhenRequested(): void
    {
        $provider = $this->people(10);
        $client = $this->people(20);
        $eligibleProduct = $this->product(30, 25.0);

        $latestOpenCard = $this->order(
            600,
            Order::ORDER_TYPE_FIDELITY,
            $provider,
            $client,
            $this->orderStatus('open', 'open'),
            '2026-07-12T10:00:00Z',
        );
        $olderClosedCard = $this->order(
            500,
            Order::ORDER_TYPE_FIDELITY,
            $provider,
            $client,
            $this->orderStatus('closed', 'closed'),
            '2026-07-11T10:00:00Z',
        );

        $latestStamp = $this->order(
            701,
            Order::ORDER_TYPE_SALE,
            $provider,
            $client,
            $this->orderStatus('closed', 'closed'),
            '2026-07-12T11:00:00Z',
        );
        $latestStamp->setMainOrderId(600);
        $latestStamp->addOrderProduct($this->orderProduct($latestStamp, $eligibleProduct, 25.0));

        $olderStamp = $this->order(
            801,
            Order::ORDER_TYPE_SALE,
            $provider,
            $client,
            $this->orderStatus('closed', 'closed'),
            '2026-07-11T11:00:00Z',
        );
        $olderStamp->setMainOrderId(500);
        $olderStamp->addOrderProduct($this->orderProduct($olderStamp, $eligibleProduct, 25.0));

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository
            ->method('findBy')
            ->willReturnCallback(function (
                array $criteria,
                array $orderBy = [],
                $limit = null,
                $offset = null,
            ) use (
                $latestOpenCard,
                $olderClosedCard,
                $latestStamp,
                $olderStamp,
                $provider,
                $client,
            ): array {
                if (
                    ($criteria['orderType'] ?? null) === Order::ORDER_TYPE_FIDELITY &&
                    ($criteria['provider'] ?? null) === $provider &&
                    ($criteria['client'] ?? null) === $client
                ) {
                    return [$latestOpenCard, $olderClosedCard];
                }

                if (($criteria['mainOrderId'] ?? null) === 600) {
                    return [$latestStamp];
                }

                if (($criteria['mainOrderId'] ?? null) === 500) {
                    return [$olderStamp];
                }

                return [];
            });

        $manager = $this->manager([Order::class => $orderRepository]);
        $service = new OrderLoyaltySnapshotService($manager, $this->configService());

        $cards = $service->buildForClient($provider, $client, true);

        self::assertCount(2, $cards);
        self::assertSame([600, 500], array_map(
            static fn (array $card): int => (int) $card['card']['id'],
            $cards,
        ));
        self::assertSame([701], array_map(
            static fn (array $stamp): int => (int) $stamp['id'],
            $cards[0]['stamps'],
        ));
        self::assertSame([801], array_map(
            static fn (array $stamp): int => (int) $stamp['id'],
            $cards[1]['stamps'],
        ));
    }

    private function configService(): ConfigService
    {
        $configService = $this->createMock(ConfigService::class);
        $configService
            ->method('getConfig')
            ->willReturnCallback(
                fn (People $people, string $key) => match ($key) {
                    'shop-loyalty-coupons-enabled' => '1',
                    'shop-loyalty-product-ids' => '[30]',
                    'shop-loyalty-required-sales' => '3',
                    default => null,
                },
            );

        return $configService;
    }

    /**
     * @param array<class-string, EntityRepository> $repositories
     */
    private function manager(array $repositories): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->method('getRepository')
            ->willReturnCallback(fn (string $class) => $repositories[$class]);

        return $manager;
    }

    private function order(
        int $id,
        string $orderType,
        People $provider,
        People $client,
        Status $status,
        string $orderDate,
    ): Order {
        $order = new Order();
        $this->setEntityId($order, $id);
        $order->setOrderType($orderType);
        $order->setProvider($provider);
        $order->setClient($client);
        $order->setPayer($client);
        $order->setStatus($status);
        $order->setApp('SHOP');
        $order->setOrderDate(new \DateTimeImmutable($orderDate));
        $order->setAlterDate(new \DateTimeImmutable($orderDate));

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
