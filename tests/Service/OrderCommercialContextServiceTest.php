<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\DeviceService;
use ControleOnline\Service\OrderCommercialContextService;
use ControleOnline\Service\PeopleService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[AllowMockObjectsWithoutExpectations]
class OrderCommercialContextServiceTest extends TestCase
{
    public static function settlementTypeProvider(): array
    {
        return [
            'table' => [Order::ORDER_TYPE_TABLE],
            'tab' => [Order::ORDER_TYPE_TAB],
        ];
    }

    #[DataProvider('settlementTypeProvider')]
    public function testChildRoundKeepsExistingTableOrTabRoot(string $rootType): void
    {
        $provider = $this->createPeople(11);
        $root = (new Order())
            ->setProvider($provider)
            ->setOrderType($rootType);
        $child = (new Order())
            ->setApp('POS')
            ->setProvider($provider)
            ->setOrderType(Order::ORDER_TYPE_CART)
            ->setMainOrder($root);

        $this->buildService()->prepare($child);

        self::assertSame($root, $child->getMainOrder());
    }

    public function testRejectsCrossTenantMainOrder(): void
    {
        $root = (new Order())
            ->setProvider($this->createPeople(20))
            ->setOrderType(Order::ORDER_TYPE_TABLE);
        $child = (new Order())
            ->setApp('SHOP')
            ->setProvider($this->createPeople(21))
            ->setOrderType(Order::ORDER_TYPE_CART)
            ->setMainOrder($root);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Vinculo de pedido entre tenants nao e permitido.');
        $this->buildService()->prepare($child);
    }

    public function testExistingLogisticsChildKeepsDistinctProviderRelationship(): void
    {
        $merchant = $this->createPeople(22);
        $root = (new Order())
            ->setProvider($merchant)
            ->setOrderType(Order::ORDER_TYPE_SALE);
        $delivery = (new Order())
            ->setApp(Order::APP_FOOD99)
            ->setProvider($this->createPeople(23))
            ->setClient($merchant)
            ->setOrderType(Order::ORDER_TYPE_DELIVERY)
            ->setMainOrder($root);

        $this->buildService()->prepare($delivery);

        self::assertSame($root, $delivery->getMainOrder());
    }

    public function testDeviceWithoutExplicitCapabilityFailsClosedRegardlessOfOperationalPreset(): void
    {
        foreach (['waiter', 'cashier'] as $operationMode) {
            [$service, $order] = $this->buildChargeContext('POS', [
                'pos-operation-mode' => $operationMode,
            ]);

            $capability = $service->resolveChargeCapability($order);
            self::assertFalse($capability['local']);
            self::assertSame('device-config-missing', $capability['source']);
        }
    }

    public function testExplicitDeviceCapabilityBlocksWaiterAtBackend(): void
    {
        [$service, $order] = $this->buildChargeContext('POS', [
            'pos-operation-mode' => 'waiter',
            OrderCommercialContextService::CHARGE_CONFIG_KEY => false,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('nao possui capacidade para cobranca local');
        $service->assertChargeAllowed($order);
    }

    public function testExplicitCapabilityAllowsWaiterAndCashierWithoutInferringFromMode(): void
    {
        foreach (['waiter', 'cashier'] as $operationMode) {
            [$service, $order] = $this->buildChargeContext('POS', [
                'pos-operation-mode' => $operationMode,
                OrderCommercialContextService::CHARGE_CONFIG_KEY => true,
            ]);

            $service->assertChargeAllowed($order);
            self::assertTrue($service->resolveChargeCapability($order)['local']);
        }
    }

    public function testManagerCannotChargeLocallyButKeepsRemoteAndExternalModes(): void
    {
        [$service, $order] = $this->buildChargeContext('MANAGER', [
            OrderCommercialContextService::CHARGE_CONFIG_KEY => true,
        ]);
        $capability = $service->resolveChargeCapability($order);

        self::assertFalse($capability['local']);
        self::assertTrue($capability['remote']);
        self::assertTrue($capability['external']);

        $this->expectException(AccessDeniedHttpException::class);
        $service->assertChargeAllowed($order, OrderCommercialContextService::CHARGE_MODE_LOCAL);
    }

    public function testClientWritableMetadataCannotDisguiseManagerAsPos(): void
    {
        [$service, $order] = $this->buildChargeContext(
            'POS',
            [OrderCommercialContextService::CHARGE_CONFIG_KEY => true],
            true,
            'MANAGER',
        );

        $capability = $service->resolveChargeCapability($order);
        self::assertSame('MANAGER', $capability['appType']);
        self::assertFalse($capability['local']);
        self::assertTrue($capability['remote']);

        $this->expectException(AccessDeniedHttpException::class);
        $service->assertChargeAllowed($order, OrderCommercialContextService::CHARGE_MODE_LOCAL);
    }

    public function testUnknownPersistedDeviceContextCannotGrantLocalCharge(): void
    {
        [$service, $order] = $this->buildChargeContext(
            'POS',
            [OrderCommercialContextService::CHARGE_CONFIG_KEY => true],
            true,
            'DEVICE',
        );

        $capability = $service->resolveChargeCapability($order);
        self::assertFalse($capability['enabled']);
        self::assertFalse($capability['local']);
        self::assertSame('device-context-untrusted', $capability['source']);
    }

    public function testActorOutsideOrderTenantCannotCharge(): void
    {
        [$service, $order] = $this->buildChargeContext('POS', [], false);

        $this->expectException(AccessDeniedHttpException::class);
        $service->assertChargeAllowed($order);
    }

    public function testMissingDeviceHeaderCannotBypassBackendAuthorization(): void
    {
        $provider = $this->createPeople(40);
        $order = (new Order())->setProvider($provider);
        $peopleService = $this->createMock(PeopleService::class);
        $peopleService->method('getMyCompanies')->willReturn([$provider]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->buildService(null, $peopleService)->assertChargeAllowed($order);
    }

    private function buildService(
        ?EntityManagerInterface $manager = null,
        ?PeopleService $peopleService = null,
        ?DeviceService $deviceService = null,
        ?Request $request = null,
    ): OrderCommercialContextService {
        $requestStack = new RequestStack();
        $requestStack->push($request ?? Request::create('/orders', 'GET'));

        return new OrderCommercialContextService(
            $manager ?? $this->createMock(EntityManagerInterface::class),
            $peopleService ?? $this->createMock(PeopleService::class),
            $deviceService ?? $this->createMock(DeviceService::class),
            $requestStack,
        );
    }

    private function buildChargeContext(
        string $appType,
        array $configs,
        bool $actorHasCompany = true,
        ?string $deviceConfigType = null,
    ): array {
        $provider = $this->createPeople(30);
        $order = (new Order())->setProvider($provider);
        $device = (new Device())
            ->setDevice('device-30')
            ->setMetadata(['appType' => $appType]);
        $deviceConfig = (new DeviceConfig())
            ->setPeople($provider)
            ->setDevice($device)
            ->setType($deviceConfigType ?? ($appType === 'MANAGER' ? 'MANAGER' : 'PDV'))
            ->setConfigs($configs);
        $order->setDevice($device);

        $deviceRepository = $this->createMock(EntityRepository::class);
        $deviceRepository
            ->method('findOneBy')
            ->with(['device' => 'device-30'])
            ->willReturn($device);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->method('getRepository')
            ->with(Device::class)
            ->willReturn($deviceRepository);

        $peopleService = $this->createMock(PeopleService::class);
        $peopleService
            ->method('getMyCompanies')
            ->willReturn($actorHasCompany ? [$provider] : [$this->createPeople(31)]);

        $deviceService = $this->createMock(DeviceService::class);
        $deviceService
            ->method('findDeviceConfigs')
            ->with($device, $provider)
            ->willReturn([$deviceConfig]);
        $deviceService
            ->method('findDeviceConfig')
            ->with($device, $provider)
            ->willReturn($deviceConfig);

        $request = Request::create('/invoices', 'POST');
        $request->headers->set('DEVICE', 'device-30');

        return [
            $this->buildService($manager, $peopleService, $deviceService, $request),
            $order,
            $deviceConfig,
        ];
    }

    private function createPeople(int $id): People
    {
        $people = new People();
        $property = new \ReflectionProperty(People::class, 'id');
        $property->setAccessible(true);
        $property->setValue($people, $id);

        return $people;
    }
}
