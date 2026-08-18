<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
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
    public function testPrepareInfersCanonicalChannelAndCreatesPrivacySafeSnapshot(): void
    {
        $order = (new Order())
            ->setApp('POS')
            ->setOrderType(Order::ORDER_TYPE_CART)
            ->setFulfillmentType(Order::FULFILLMENT_COUNTER);

        $this->buildService()->prepare($order);

        self::assertSame(Order::CHANNEL_POS, $order->getChannel());
        self::assertSame(Order::FULFILLMENT_COUNTER, $order->getFulfillmentType());
        self::assertFalse($order->isPayBeforeProductionRequired());
        self::assertSame('none', $order->getOperationalSnapshot()['linkType']);
        self::assertArrayNotHasKey('deviceId', $order->getOperationalSnapshot());
        self::assertArrayNotHasKey('mainOrderId', $order->getOperationalSnapshot());
    }

    #[DataProvider('channelProvider')]
    public function testCanonicalChannelsPreserveAppAsIndependentPlatform(
        string $app,
        ?string $channel,
        string $expectedChannel,
    ): void {
        $order = (new Order())
            ->setApp($app)
            ->setChannel($channel)
            ->setOrderType(Order::ORDER_TYPE_CART);

        $this->buildService()->prepare($order);

        self::assertSame($expectedChannel, $order->getChannel());
        self::assertSame($app, $order->getApp());
    }

    public static function channelProvider(): array
    {
        return [
            'pos' => ['POS', null, Order::CHANNEL_POS],
            'shop' => ['SHOP', null, Order::CHANNEL_SHOP],
            'totem remains POS app' => ['POS', Order::CHANNEL_TOTEM, Order::CHANNEL_TOTEM],
            'iFood is an external platform, not a channel' => [Order::APP_IFOOD, null, Order::CHANNEL_EXTERNAL],
            '99 is an external platform, not a channel' => [Order::APP_FOOD99, null, Order::CHANNEL_EXTERNAL],
        ];
    }

    #[DataProvider('fulfillmentProvider')]
    public function testAcceptsOnlyCanonicalFulfillmentIntent(string $fulfillmentType): void
    {
        $order = (new Order())
            ->setApp('SHOP')
            ->setOrderType(Order::ORDER_TYPE_CART)
            ->setFulfillmentType($fulfillmentType);

        $this->buildService()->prepare($order);

        self::assertSame($fulfillmentType, $order->getFulfillmentType());
    }

    public static function fulfillmentProvider(): array
    {
        return array_map(static fn(string $type): array => [$type], Order::FULFILLMENT_TYPES);
    }

    public function testRejectsUnknownChannelAndFulfillmentWithoutCreatingEnums(): void
    {
        $service = $this->buildService();
        $invalidChannel = (new Order())->setApp('POS')->setChannel('whatsapp');

        try {
            $service->prepare($invalidChannel);
            self::fail('Unknown channel should have been rejected.');
        } catch (BadRequestHttpException $exception) {
            self::assertSame('Canal do pedido invalido.', $exception->getMessage());
        }

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Tipo de fulfillment invalido.');
        $service->prepare(
            (new Order())->setApp('POS')->setFulfillmentType('drive_thru'),
        );
    }

    public function testFalseOrAbsentPaymentPolicyDoesNotRequirePayment(): void
    {
        $service = $this->buildService();

        foreach ([null, false] as $policy) {
            $order = (new Order())
                ->setApp('POS')
                ->setOrderType(Order::ORDER_TYPE_CART)
                ->setPrice(100)
                ->setPayBeforeProduction($policy);

            $service->assertConfirmationAllowed($order);
            self::assertFalse($order->isPayBeforeProductionRequired());
        }
    }

    public function testPaymentPolicyRequiresFullSettlementOfIndependentOrder(): void
    {
        $order = (new Order())
            ->setApp('POS')
            ->setOrderType(Order::ORDER_TYPE_CART)
            ->setPrice(100)
            ->setPayBeforeProduction(true);
        $order->addInvoice($this->createPaidOrderInvoice($order, 40));

        $service = $this->buildService();

        try {
            $service->assertConfirmationAllowed($order);
            self::fail('Partial payment should not confirm the order.');
        } catch (BadRequestHttpException $exception) {
            self::assertStringContainsString('Pagamento integral', $exception->getMessage());
        }

        $order->addInvoice($this->createPaidOrderInvoice($order, 60));
        $service->assertConfirmationAllowed($order);
        self::assertTrue($service->isOwnOrderFullyPaid($order));
    }

    #[DataProvider('settlementTypeProvider')]
    public function testPaymentBeforeProductionIsRejectedForTableAndTab(string $rootType): void
    {
        $provider = $this->createPeople(10);
        $root = (new Order())
            ->setProvider($provider)
            ->setOrderType($rootType);
        $child = (new Order())
            ->setApp('POS')
            ->setProvider($provider)
            ->setOrderType(Order::ORDER_TYPE_CART)
            ->setMainOrder($root)
            ->setPayBeforeProduction(true);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('pay_before_production nao e suportado');
        $this->buildService()->prepare($child);
    }

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
            ->setMainOrder($root)
            ->setFulfillmentType(Order::FULFILLMENT_DINE_IN);

        $this->buildService()->prepare($child);

        self::assertSame($root, $child->getMainOrder());
        self::assertSame($rootType, $child->getOperationalSnapshot()['linkType']);
        self::assertFalse($child->isPayBeforeProductionRequired());
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
            ->setMainOrder($root)
            ->setFulfillmentType(Order::FULFILLMENT_DELIVERY);

        $this->buildService()->prepare($delivery);

        self::assertSame(Order::CHANNEL_EXTERNAL, $delivery->getChannel());
        self::assertSame(Order::FULFILLMENT_DELIVERY, $delivery->getFulfillmentType());
        self::assertSame('none', $delivery->getOperationalSnapshot()['linkType']);
    }

    public function testFrozenSaleContextCannotBeRewritten(): void
    {
        $order = (new Order())
            ->setApp('POS')
            ->setChannel(Order::CHANNEL_POS)
            ->setOrderType(Order::ORDER_TYPE_SALE)
            ->setFulfillmentType(Order::FULFILLMENT_PICKUP);
        $service = $this->buildService();
        $service->freezeConfirmedContext($order);

        $order->setFulfillmentType(Order::FULFILLMENT_DELIVERY);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Contexto comercial confirmado nao pode ser alterado livremente.');
        $service->prepare($order, true);
    }

    public function testLegacyPosDeviceCanChargeRegardlessOfOperationalPreset(): void
    {
        foreach (['waiter', 'cashier'] as $operationMode) {
            [$service, $order] = $this->buildChargeContext('POS', [
                'pos-operation-mode' => $operationMode,
            ]);

            $capability = $service->resolveChargeCapability($order);
            self::assertTrue($capability['local']);
            self::assertSame('legacy-default', $capability['source']);
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
        [$service, $order] = $this->buildChargeContext('MANAGER', []);
        $capability = $service->resolveChargeCapability($order);

        self::assertFalse($capability['local']);
        self::assertTrue($capability['remote']);
        self::assertTrue($capability['external']);

        $this->expectException(AccessDeniedHttpException::class);
        $service->assertChargeAllowed($order, OrderCommercialContextService::CHARGE_MODE_LOCAL);
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
    ): array {
        $provider = $this->createPeople(30);
        $order = (new Order())->setProvider($provider);
        $device = (new Device())
            ->setDevice('device-30')
            ->setMetadata(['appType' => $appType]);
        $deviceConfig = (new DeviceConfig())
            ->setPeople($provider)
            ->setDevice($device)
            ->setType('PDV')
            ->setConfigs($configs);

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
        ];
    }

    private function createPaidOrderInvoice(Order $order, float $amount): OrderInvoice
    {
        $status = (new Status())->setStatus('paid')->setRealStatus('closed');
        $invoice = (new Invoice())->setStatus($status)->setPrice($amount);

        return (new OrderInvoice())
            ->setOrder($order)
            ->setInvoice($invoice)
            ->setRealPrice($amount);
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
