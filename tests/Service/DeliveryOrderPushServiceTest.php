<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use ControleOnline\Event\EntityChangedEvent;
use ControleOnline\Service\DeliveryOrderPushService;
use ControleOnline\Service\FirebaseCloudMessagingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeliveryOrderPushServiceTest extends TestCase
{
    public function testPostPersistSendsNotificationToDeliveryDeviceConfig(): void
    {
        $courier = $this->people(20, 'Courier');
        $provider = $this->people(10, 'Store');
        $order = $this->order(100, $provider, $courier, $this->createStatus('pending', 'pending'));
        $order->setOrderType(Order::ORDER_TYPE_DELIVERY);

        $device = new Device();
        $device->setDevice('android-delivery');
        $device->setMetadata([
            'pushTokens' => [
                'delivery' => [
                    'android' => [
                        'deviceToken' => 'fcm-token-1',
                    ],
                ],
            ],
        ]);

        $deviceConfig = (new DeviceConfig())
            ->setPeople($courier)
            ->setDevice($device)
            ->setType('DELIVERY');

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(['people' => $courier])
            ->willReturn([$deviceConfig]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(DeviceConfig::class)
            ->willReturn($repository);

        $firebaseCloudMessagingService = $this->createMock(FirebaseCloudMessagingService::class);
        $firebaseCloudMessagingService
            ->expects(self::once())
            ->method('sendNotificationToToken')
            ->with(
                'fcm-token-1',
                'Pedido #100 aguardando aceite',
                'Store: aceite a corrida para assumir a entrega.',
                self::callback(static function (array $data): bool {
                    return ($data['event'] ?? null) === 'delivery.awaiting_acceptance'
                        && ($data['orderId'] ?? null) === '100'
                        && ($data['deliveryPeopleId'] ?? null) === '20';
                })
            );

        $service = new DeliveryOrderPushService(
            $entityManager,
            $firebaseCloudMessagingService,
            $this->createStub(LoggerInterface::class)
        );

        self::assertSame(1, $service->sendAwaitingAcceptanceNotification($order));
    }

    public function testPostUpdateSendsOnlyWhenOrderTransitionsToAwaitingAcceptance(): void
    {
        $courier = $this->people(20, 'Courier');
        $provider = $this->people(10, 'Store');
        $currentOrder = $this->order(100, $provider, $courier, $this->createStatus('pending', 'pending'));
        $currentOrder->setOrderType(Order::ORDER_TYPE_DELIVERY);

        $previousOrder = $this->order(100, $provider, $courier, $this->createStatus('open', 'open'));
        $previousOrder->setOrderType(Order::ORDER_TYPE_DELIVERY);

        $device = new Device();
        $device->setDevice('android-delivery');
        $device->setMetadata([
            'pushTokens' => [
                'delivery' => [
                    'android' => [
                        'deviceToken' => 'fcm-token-2',
                    ],
                ],
            ],
        ]);

        $deviceConfig = (new DeviceConfig())
            ->setPeople($courier)
            ->setDevice($device)
            ->setType('DELIVERY');

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(['people' => $courier])
            ->willReturn([$deviceConfig]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(DeviceConfig::class)
            ->willReturn($repository);

        $firebaseCloudMessagingService = $this->createMock(FirebaseCloudMessagingService::class);
        $firebaseCloudMessagingService
            ->expects(self::once())
            ->method('sendNotificationToToken')
            ->with(
                'fcm-token-2',
                'Pedido #100 aguardando aceite',
                'Store: aceite a corrida para assumir a entrega.',
                self::isType('array')
            );

        $service = new DeliveryOrderPushService(
            $entityManager,
            $firebaseCloudMessagingService,
            $this->createStub(LoggerInterface::class)
        );

        $service->onEntityChanged(
            new EntityChangedEvent($currentOrder, 'postUpdate', $previousOrder)
        );
    }

    public function testPostUpdateDoesNotResendWhenCourierAndStatusDidNotChange(): void
    {
        $courier = $this->people(20, 'Courier');
        $provider = $this->people(10, 'Store');
        $currentOrder = $this->order(100, $provider, $courier, $this->createStatus('pending', 'pending'));
        $currentOrder->setOrderType(Order::ORDER_TYPE_DELIVERY);

        $previousOrder = $this->order(100, $provider, $courier, $this->createStatus('pending', 'pending'));
        $previousOrder->setOrderType(Order::ORDER_TYPE_DELIVERY);

        $firebaseCloudMessagingService = $this->createMock(FirebaseCloudMessagingService::class);
        $firebaseCloudMessagingService
            ->expects(self::never())
            ->method('sendNotificationToToken');

        $service = new DeliveryOrderPushService(
            $this->createMock(EntityManagerInterface::class),
            $firebaseCloudMessagingService,
            $this->createStub(LoggerInterface::class)
        );

        $service->onEntityChanged(
            new EntityChangedEvent($currentOrder, 'postUpdate', $previousOrder)
        );
    }

    private function order(
        int $id,
        People $provider,
        People $courier,
        Status $status
    ): Order {
        $order = new Order();
        $this->setEntityId($order, $id);
        $order->setProvider($provider);
        $order->setClient($provider);
        $order->setPayer($provider);
        $order->setDeliveryPeople($courier);
        $order->setStatus($status);
        $order->setApp('DELIVERY');

        return $order;
    }

    private function people(int $id, string $name): People
    {
        $people = new People();
        $this->setEntityId($people, $id);
        $people->setName($name);
        $people->setAlias($name);

        return $people;
    }

    private function createStatus(string $status, string $realStatus, string $context = 'order'): Status
    {
        $entity = new Status();
        $entity->setStatus($status);
        $entity->setRealStatus($realStatus);
        $entity->setContext($context);

        return $entity;
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

        throw new \RuntimeException(sprintf('Unable to set id for %s.', $entity::class));
    }
}
