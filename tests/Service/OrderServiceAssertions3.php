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
trait OrderServiceAssertions3
{
    public function testSecurityFilterBlocksClientUsersFromRequestingAnotherClientId(): void
    {
        $currentPeople = new People();
        $this->setEntityId(People::class, $currentPeople, 31484);

        $service = $this->buildService(
            '/orders',
            null,
            null,
            null,
            null,
            [],
            [],
            $currentPeople,
            ['client' => '/people/999'],
            null,
            ['ROLE_CLIENT'],
        );
        $queryBuilder = $this->createMock(QueryBuilder::class);

        $whereClauses = [];
        $parameters = [];

        $queryBuilder
            ->expects(self::exactly(2))
            ->method('andWhere')
            ->willReturnCallback(function (string $expression) use (&$whereClauses, $queryBuilder) {
                $whereClauses[] = $expression;
                return $queryBuilder;
            });

        $queryBuilder
            ->expects(self::once())
            ->method('setParameter')
            ->willReturnCallback(function (string $name, mixed $value) use (&$parameters, $queryBuilder) {
                $parameters[$name] = $value;
                return $queryBuilder;
            });

        $service->securityFilter($queryBuilder, null, null, 'orders');

        self::assertContains('(orders.client = :currentPeople OR orders.payer = :currentPeople)', $whereClauses);
        self::assertContains('1 = 0', $whereClauses);
        self::assertSame($currentPeople, $parameters['currentPeople']);
    }

    public function testPreferredProductGroupProductLinkUsesHiddenMappingFirst(): void
    {
        $service = $this->buildService('/orders');

        $visibleGroup = $this->createProductGroup(321, 'Molhos extra à parte');
        $hiddenGroup = $this->createProductGroup(100, 'Molhos extra à parte - 60ml');

        $visibleLink = $this->createProductGroupProductLink(1850, $visibleGroup, true);
        $hiddenLink = $this->createProductGroupProductLink(380, $hiddenGroup, false);

        $resolved = $this->invokePrivateMethod($service, 'pickPreferredProductGroupProductLink', [
            [$visibleLink, $hiddenLink],
            $visibleGroup,
        ]);

        self::assertSame($hiddenLink, $resolved);
        self::assertFalse($resolved->getShowInParentQueue());
        self::assertSame(100, $resolved->getProductGroup()->getId());
    }

    public function testNormalizeOrderProductGroupLinksKeepsStandaloneOrderProductAtRoot(): void
    {
        $order = new Order();
        $parentProduct = new Product();
        $childProduct = new Product();

        $parentOrderProduct = (new OrderProduct())
            ->setOrder($order)
            ->setProduct($parentProduct);
        $this->setEntityId(OrderProduct::class, $parentOrderProduct, 106932);

        $standaloneOrderProduct = (new OrderProduct())
            ->setOrder($order)
            ->setProduct($childProduct);
        $this->setEntityId(OrderProduct::class, $standaloneOrderProduct, 106939);

        $order->addOrderProduct($parentOrderProduct);
        $order->addOrderProduct($standaloneOrderProduct);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::never())
            ->method('getRepository');
        $entityManager
            ->expects(self::never())
            ->method('flush');
        $entityManager
            ->expects(self::never())
            ->method('refresh');

        $service = $this->buildService('/orders', $entityManager);

        self::assertFalse($service->normalizeOrderProductGroupLinks($order));
        self::assertNull($standaloneOrderProduct->getOrderProduct());
        self::assertNull($standaloneOrderProduct->getParentProduct());
        self::assertNull($standaloneOrderProduct->getProductGroup());
    }

    public function testPostPersistQueuesManagerPushNotificationForSaleOrder(): void
    {
        $provider = $this->createMock(People::class);
        $provider
            ->method('getId')
            ->willReturn(3);

        $status = $this->createMock(Status::class);
        $status
            ->method('getRealStatus')
            ->willReturn('open');

        $order = new Order();
        $order->setProvider($provider);
        $order->setStatus($status);
        $order->setOrderType(OrderService::ORDER_TYPE_SALE);
        $this->setEntityId(Order::class, $order, 71608);

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $repository
            ->expects(self::exactly(2))
            ->method('findBy')
            ->with(['people' => $provider])
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(2))
            ->method('getRepository')
            ->with(DeviceConfig::class)
            ->willReturn($repository);

        $integrationService = $this->createMock(IntegrationService::class);
        $integrationService
            ->expects(self::once())
            ->method('addManagerPushIntegrations')
            ->with(
                self::callback(static function (string $payload): bool {
                    $decoded = json_decode($payload, true);

                    return is_array($decoded)
                        && ($decoded['event'] ?? null) === 'order.created'
                        && ($decoded['orderId'] ?? null) === '71608'
                        && ($decoded['companyId'] ?? null) === '3';
                }),
                $provider
            )
            ->willReturn(0);

        $service = $this->buildService(
            '/orders',
            $entityManager,
            null,
            null,
            $integrationService
        );

        $service->postPersist($order);
    }

    public function testPostPersistDoesNotQueueManagerPushNotificationForCartOrder(): void
    {
        $provider = $this->createMock(People::class);
        $provider
            ->method('getId')
            ->willReturn(3);

        $status = $this->createMock(Status::class);
        $status
            ->method('getRealStatus')
            ->willReturn('open');

        $order = new Order();
        $order->setProvider($provider);
        $order->setStatus($status);
        $order->setOrderType(OrderService::ORDER_TYPE_CART);
        $this->setEntityId(Order::class, $order, 71608);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::never())
            ->method('getRepository');

        $integrationService = $this->createMock(IntegrationService::class);
        $integrationService
            ->expects(self::never())
            ->method('addManagerPushIntegrations');

        $service = $this->buildService(
            '/orders',
            $entityManager,
            null,
            null,
            $integrationService
        );

        $service->postPersist($order);
    }

    public function testSettlementDetailProjectsExistingChargePolicyWithoutPersistingIt(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::never())->method('flush');
        $capability = ['enabled' => true, 'local' => false, 'remote' => true, 'external' => true, 'source' => 'device-config', 'appType' => 'MANAGER'];
        $policy = $this->createMock(OrderCommercialContextService::class);
        $policy->expects(self::once())->method('resolveChargeCapability')->willReturn($capability);
        $service = $this->buildService('/orders/1', entityManager: $manager, commercialContextService: $policy);
        $tab = (new Order())->setOrderType('tab');
        $service->prepareOrderDetailsRead($tab);
        self::assertSame($capability, $tab->getChargeCapability());
        $sale = (new Order())->setOrderType('sale');
        $service->prepareOrderDetailsRead($sale);
        self::assertNull($sale->getChargeCapability());
    }
}
