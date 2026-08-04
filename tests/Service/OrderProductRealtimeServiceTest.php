<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\OrderProductRealtimeService;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class OrderProductRealtimeServiceTest extends TestCase
{
    public function testCheckedItemBroadcastsOnePayloadPerDevice(): void
    {
        $company = $this->createStub(People::class);
        $company->method('getId')->willReturn(3);
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(72850);
        $order->method('getProvider')->willReturn($company);
        $orderProduct = $this->createStub(OrderProduct::class);
        $orderProduct->method('getId')->willReturn(990);
        $orderProduct->method('getOrder')->willReturn($order);
        $device = $this->createStub(Device::class);
        $device->method('getId')->willReturn(1103);
        $firstConfig = $this->createStub(DeviceConfig::class);
        $firstConfig->method('getDevice')->willReturn($device);
        $duplicateConfig = $this->createStub(DeviceConfig::class);
        $duplicateConfig->method('getDevice')->willReturn($device);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->with(['people' => $company])->willReturn([
            $firstConfig,
            $duplicateConfig,
        ]);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->with(DeviceConfig::class)->willReturn($repository);
        $websocketClient = $this->createMock(WebsocketClient::class);
        $websocketClient
            ->expects(self::once())
            ->method('push')
            ->with(
                $device,
                self::callback(function (string $payload): bool {
                    $events = json_decode($payload, true);

                    self::assertSame(['order_products', 'orders'], array_column($events, 'store'));
                    self::assertSame('order_product.checked', $events[0]['event']);
                    self::assertSame(3, $events[0]['company']);
                    self::assertSame(72850, $events[0]['order']);
                    self::assertSame(990, $events[0]['orderProduct']);

                    return true;
                }),
            )
            ->willReturn($this->createStub(Integration::class));

        $service = new OrderProductRealtimeService($manager, $websocketClient);

        self::assertSame(1, $service->notifyChecked($orderProduct));
    }
}
