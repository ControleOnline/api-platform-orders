<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Device;
use ControleOnline\Entity\Inventory;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\DeviceService;
use ControleOnline\Service\OrderProductQueueService;
use ControleOnline\Service\OrderCommercialContextService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\StatusService;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\SerializerInterface;

/** Methods shared by the original class; contracts and visibility are unchanged. */
trait OrderServiceAssertions1
{
    public function testCreateOrderStartsPosFlowAsCart(): void
    {
        $receiver = $this->createMock(People::class);
        $payer = $this->createMock(People::class);
        $draftStatus = $this->createMock(Status::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $statusService = $this->createMock(StatusService::class);

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('open', 'open', 'order')
            ->willReturn($draftStatus);

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($receiver, $payer, $draftStatus): bool {
                return $entity instanceof Order
                    && $entity->getProvider() === $receiver
                    && $entity->getClient() === $payer
                    && $entity->getPayer() === $payer
                    && $entity->getStatus() === $draftStatus
                    && $entity->getOrderType() === OrderService::ORDER_TYPE_CART
                    && $entity->getApp() === 'POS';
            }));

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service = $this->buildService('/orders', $entityManager, $statusService);

        $order = $service->createOrder($receiver, $payer, 'POS');

        self::assertSame(OrderService::ORDER_TYPE_CART, $order->getOrderType());
        self::assertSame($draftStatus, $order->getStatus());
    }

    public function testCreateOrderRecalculatesProvisionalDefaultAfterDeviceAssociation(): void
    {
        $receiver = $this->createMock(People::class);
        $payer = $this->createMock(People::class);
        $draftStatus = $this->createMock(Status::class);
        $device = (new Device())->setDevice('pdv-after-create');
        $deviceConfig = (new DeviceConfig())
            ->setPeople($receiver)
            ->setDevice($device)
            ->setType('PDV')
            ->setConfigs([
                OrderCommercialContextService::PAY_BEFORE_PRODUCTION_CONFIG_KEY => true,
            ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(Order::class));
        $entityManager->expects(self::once())->method('flush');
        $statusService = $this->createMock(StatusService::class);
        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('open', 'open', 'order')
            ->willReturn($draftStatus);
        $deviceService = $this->createMock(DeviceService::class);
        $deviceService
            ->expects(self::once())
            ->method('findDeviceConfigs')
            ->with($device, $receiver)
            ->willReturn([$deviceConfig]);
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/orders', 'POST'));
        $commercialContext = new OrderCommercialContextService(
            $entityManager,
            $this->createMock(PeopleService::class),
            $deviceService,
            $requestStack,
        );
        $queueService = $this->createMock(OrderProductQueueService::class);
        $queueService->expects(self::once())->method('ensureOrderQueueEntries');
        $service = $this->buildService(
            '/orders',
            $entityManager,
            $statusService,
            $queueService,
            commercialContextService: $commercialContext,
        );

        // createOrder prepares before DefaultEventListener discovers the device.
        $order = $service->createOrder($receiver, $payer, 'POS');
        self::assertFalse($order->isPayBeforeProductionRequired());
        self::assertSame('default', $order->getOperationalSnapshot()['payBeforeProductionSource']);
        self::assertArrayNotHasKey('confirmedAt', $order->getOperationalSnapshot());

        // Simulate DefaultEventListener device discovery followed by OrderService::prePersist.
        $order->setDevice($device);
        $service->prePersist($order);
        self::assertTrue($order->isPayBeforeProductionRequired());
        self::assertSame('device-config', $order->getOperationalSnapshot()['payBeforeProductionSource']);

        self::assertTrue($service->convertDraftOrderToSale($order));
        $confirmedSnapshot = $order->getOperationalSnapshot();
        self::assertArrayHasKey('confirmedAt', $confirmedSnapshot);

        $deviceConfig->setConfigs([
            OrderCommercialContextService::PAY_BEFORE_PRODUCTION_CONFIG_KEY => false,
        ]);
        $commercialContext->prepare($order, true);

        self::assertTrue($order->isPayBeforeProductionRequired());
        self::assertSame('device-config', $order->getOperationalSnapshot()['payBeforeProductionSource']);
        self::assertSame($confirmedSnapshot, $order->getOperationalSnapshot());
    }

    public function testCreateOrderStartsMarketplaceFlowAsSale(): void
    {
        $receiver = $this->createMock(People::class);
        $payer = $this->createMock(People::class);
        $pendingStatus = $this->createMock(Status::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $statusService = $this->createMock(StatusService::class);

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('pending', 'waiting payment', 'order')
            ->willReturn($pendingStatus);

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($pendingStatus): bool {
                return $entity instanceof Order
                    && $entity->getStatus() === $pendingStatus
                    && $entity->getOrderType() === OrderService::ORDER_TYPE_SALE
                    && $entity->getApp() === Order::APP_IFOOD;
            }));

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service = $this->buildService('/orders', $entityManager, $statusService);

        $order = $service->createOrder($receiver, $payer, Order::APP_IFOOD);

        self::assertSame(OrderService::ORDER_TYPE_SALE, $order->getOrderType());
        self::assertSame($pendingStatus, $order->getStatus());
    }

    public function testStampIsTreatedAsSettlementOrderType(): void
    {
        $service = $this->buildService('/orders');

        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_STAMP);

        self::assertTrue($service->isSettlementOrderType(OrderService::ORDER_TYPE_STAMP));
        self::assertTrue($service->isSettlementOrder($order));
    }

    public function testNormalizeDraftCartOrderConvertsLegacyQuoteToCart(): void
    {
        $queueService = $this->createMock(OrderProductQueueService::class);
        $order = new Order();
        $order->setApp('SHOP');
        $order->setOrderType(OrderService::ORDER_TYPE_QUOTE);

        $queueService
            ->expects(self::once())
            ->method('syncByOrderStatus')
            ->with($order);

        $service = $this->buildService('/orders', null, null, $queueService);

        self::assertTrue($service->normalizeDraftCartOrder($order));
        self::assertSame(OrderService::ORDER_TYPE_CART, $order->getOrderType());
    }

    public function testConvertDraftOrderToSalePromotesDraftAndMaterializesQueues(): void
    {
        $queueService = $this->createMock(OrderProductQueueService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $defaultOutInventory = $this->createMock(Inventory::class);
        $product = $this->createMock(Product::class);
        $product
            ->method('getDefaultOutInventory')
            ->willReturn($defaultOutInventory);

        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $order->setExternalCode('anonymous-cart:1:token');

        $orderProduct = new OrderProduct();
        $orderProduct->setOrder($order);
        $orderProduct->setProduct($product);
        $orderProduct->setQuantity(1);
        $order->addOrderProduct($orderProduct);

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use ($orderProduct, $defaultOutInventory): bool {
                return $entity === $orderProduct
                    && $entity->getOutInventory() === $defaultOutInventory;
            }));

        $queueService
            ->expects(self::once())
            ->method('ensureOrderQueueEntries')
            ->with($order);

        $commercialContextService = $this->createMock(OrderCommercialContextService::class);
        $commercialContextService
            ->expects(self::once())
            ->method('assertConfirmationAllowed')
            ->with($order);
        $commercialContextService
            ->expects(self::once())
            ->method('freezeConfirmedContext')
            ->with($order);

        $service = $this->buildService(
            '/orders',
            $entityManager,
            null,
            $queueService,
            commercialContextService: $commercialContextService,
        );

        self::assertTrue($service->convertDraftOrderToSale($order));
        self::assertSame(OrderService::ORDER_TYPE_SALE, $order->getOrderType());
        self::assertNull($order->getExternalCode());
        self::assertSame($defaultOutInventory, $orderProduct->getOutInventory());
    }

    public function testConvertDraftOrderRejectsBeforeSaleAndQueueWhenPolicyFails(): void
    {
        $order = (new Order())->setOrderType(OrderService::ORDER_TYPE_CART);
        $queueService = $this->createMock(OrderProductQueueService::class);
        $queueService->expects(self::never())->method('ensureOrderQueueEntries');
        $commercialContextService = $this->createMock(OrderCommercialContextService::class);
        $commercialContextService
            ->expects(self::once())
            ->method('assertConfirmationAllowed')
            ->with($order)
            ->willThrowException(new BadRequestHttpException('Pagamento integral obrigatorio.'));
        $commercialContextService
            ->expects(self::never())
            ->method('freezeConfirmedContext');

        $service = $this->buildService(
            '/orders',
            null,
            null,
            $queueService,
            commercialContextService: $commercialContextService,
        );

        try {
            $service->convertDraftOrderToSale($order);
            self::fail('The unpaid order should not be promoted.');
        } catch (BadRequestHttpException $exception) {
            self::assertSame('Pagamento integral obrigatorio.', $exception->getMessage());
        }

        self::assertSame(OrderService::ORDER_TYPE_CART, $order->getOrderType());
    }

    public function testRepeatedConfirmationDoesNotDuplicateQueueMaterialization(): void
    {
        $order = (new Order())->setOrderType(OrderService::ORDER_TYPE_CART);
        $queueService = $this->createMock(OrderProductQueueService::class);
        $queueService
            ->expects(self::once())
            ->method('ensureOrderQueueEntries')
            ->with($order);
        $commercialContextService = $this->createMock(OrderCommercialContextService::class);
        $commercialContextService
            ->expects(self::once())
            ->method('assertConfirmationAllowed')
            ->with($order);
        $commercialContextService
            ->expects(self::once())
            ->method('freezeConfirmedContext')
            ->with($order);
        $service = $this->buildService(
            '/orders',
            null,
            null,
            $queueService,
            commercialContextService: $commercialContextService,
        );

        self::assertTrue($service->convertDraftOrderToSale($order));
        self::assertFalse($service->convertDraftOrderToSale($order));
    }

    public function testResolvePostPaymentStatusPromotesCartToSaleBeforePaidResolution(): void
    {
        $statusService = $this->createMock(StatusService::class);
        $queueService = $this->createMock(OrderProductQueueService::class);
        $paidStatus = $this->createMock(Status::class);
        $paidStatus
            ->method('getRealStatus')
            ->willReturn('open');

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('open', 'paid', 'order')
            ->willReturn($paidStatus);

        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $order->setStatus($this->createStatusMock('open'));

        $queueService
            ->expects(self::once())
            ->method('ensureOrderQueueEntries')
            ->with($order);

        $service = $this->buildService('/orders', null, $statusService, $queueService);

        self::assertSame($paidStatus, $service->resolvePostPaymentStatus($order));
        self::assertSame(OrderService::ORDER_TYPE_SALE, $order->getOrderType());
    }

    public function testResolvePostPaymentStatusPromotesCartToSaleBeforePreparingResolution(): void
    {
        $statusService = $this->createMock(StatusService::class);
        $queueService = $this->createMock(OrderProductQueueService::class);
        $preparingStatus = $this->createMock(Status::class);
        $preparingStatus
            ->method('getRealStatus')
            ->willReturn('preparing');

        $statusService
            ->expects(self::once())
            ->method('discoveryStatus')
            ->with('open', 'preparing', 'order')
            ->willReturn($preparingStatus);

        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $order->setStatus($this->createStatusMock('open'));
        $order->setAddressDestination($this->createMock(Address::class));

        $queueService
            ->expects(self::once())
            ->method('ensureOrderQueueEntries')
            ->with($order);

        $service = $this->buildService('/orders', null, $statusService, $queueService);

        self::assertSame($preparingStatus, $service->resolvePostPaymentStatus($order));
        self::assertSame(OrderService::ORDER_TYPE_SALE, $order->getOrderType());
    }

    public function testUpdateOrderFromPayloadRejectsStatusChanges(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer
            ->expects(self::never())
            ->method('deserialize');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $queueService = $this->createMock(OrderProductQueueService::class);
        $service = $this->buildService('/orders/901', $entityManager, null, $queueService, null, [], [], null, [], $serializer);

        $order = new Order();
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $order->setStatus($this->createStatusEntity(10, 'open'));

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Status do pedido nao pode ser alterado por PUT. Use as acoes do pedido.');

        $service->updateOrderFromPayload($order, [
            'status' => '/statuses/11',
        ]);
    }
}
