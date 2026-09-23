<?php

namespace ControleOnline\Orders\Tests\EventSubscriber;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\EventSubscriber\DeviceFinancialConfigAuthorizationSubscriber;
use ControleOnline\Service\DeviceService;
use ControleOnline\Service\OrderCommercialContextService;
use ControleOnline\Service\PeopleRoleService;
use ControleOnline\Service\PeopleService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[AllowMockObjectsWithoutExpectations]
class DeviceFinancialConfigAuthorizationSubscriberTest extends TestCase
{
    public function testGuardRunsBeforeApiPlatformDeserialization(): void
    {
        self::assertSame(
            ['onKernelRequest', 5],
            DeviceFinancialConfigAuthorizationSubscriber::getSubscribedEvents()[KernelEvents::REQUEST],
        );
    }

    #[DataProvider('waiterMutationProvider')]
    public function testWaiterCannotCreateAlterRemoveOrUseAddConfigs(
        string $path,
        string $method,
        array $payload,
        string $existingType,
        array $existingConfigs,
    ): void {
        $company = $this->createPeople(90);
        $deviceConfig = $this->createDeviceConfig($company, $existingType, $existingConfigs);
        $manager = $this->buildManager($company, $deviceConfig);
        $roleService = $this->createMock(PeopleRoleService::class);
        $roleService
            ->method('getCompanyPermissions')
            ->with($company)
            ->willReturn(['employee']);
        $subscriber = new DeviceFinancialConfigAuthorizationSubscriber($manager, $roleService);
        $request = $this->createRequest($path, $method, $payload);

        try {
            $subscriber->onKernelRequest($this->createRequestEvent($request));
            self::fail('Waiter should not mutate a protected device configuration.');
        } catch (AccessDeniedHttpException) {
            self::assertTrue(true);
        }

        if (!array_key_exists(
            OrderCommercialContextService::PAY_BEFORE_PRODUCTION_CONFIG_KEY,
            $payload['configs'] ?? [],
        )) {
            $this->assertLocalChargeDenied($manager, $company, $deviceConfig);
        }
    }

    public static function waiterMutationProvider(): array
    {
        return [
            'create PDV capability' => [
                '/device_configs',
                'POST',
                [
                    'people' => '/people/90',
                    'type' => 'PDV',
                    'configs' => [OrderCommercialContextService::CHARGE_CONFIG_KEY => true],
                ],
                'DEVICE',
                [],
            ],
            'promote existing config to PDV' => [
                '/device_configs/15',
                'PUT',
                [
                    'people' => '/people/90',
                    'type' => 'PDV',
                    'configs' => [OrderCommercialContextService::CHARGE_CONFIG_KEY => true],
                ],
                'DEVICE',
                [],
            ],
            'remove Manager context' => [
                '/device_configs/15',
                'DELETE',
                [],
                'MANAGER',
                [OrderCommercialContextService::CHARGE_CONFIG_KEY => true],
            ],
            'grant capability through add-configs' => [
                '/device_configs/add-configs',
                'POST',
                [
                    'people' => '/people/90',
                    'type' => 'PDV',
                    'configs' => [OrderCommercialContextService::CHARGE_CONFIG_KEY => true],
                ],
                'DEVICE',
                [],
            ],
            'weaken temporal policy through add-configs' => [
                '/device_configs/add-configs',
                'POST',
                [
                    'people' => '/people/90',
                    'configs' => [OrderCommercialContextService::PAY_BEFORE_PRODUCTION_CONFIG_KEY => false],
                ],
                'DEVICE',
                [],
            ],
        ];
    }

    public function testAdministrativeAuthorityMustBelongToTargetTenant(): void
    {
        $targetCompany = $this->createPeople(91);
        $deviceConfig = $this->createDeviceConfig($targetCompany, 'PDV', [
            OrderCommercialContextService::CHARGE_CONFIG_KEY => false,
        ]);
        $manager = $this->buildManager($targetCompany, $deviceConfig);
        $roleService = $this->createMock(PeopleRoleService::class);
        $roleService
            ->expects(self::once())
            ->method('getCompanyPermissions')
            ->with($targetCompany)
            ->willReturn(['employee']);
        $subscriber = new DeviceFinancialConfigAuthorizationSubscriber($manager, $roleService);

        $this->expectException(AccessDeniedHttpException::class);
        $subscriber->onKernelRequest($this->createRequestEvent($this->createRequest(
            '/device_configs/15',
            'PUT',
            [
                'people' => '/people/91',
                'type' => 'PDV',
                'configs' => [OrderCommercialContextService::CHARGE_CONFIG_KEY => true],
            ],
        )));
    }

    public function testProtectedConfigCannotBeMovedToHideOriginalTenantAuthority(): void
    {
        $originalCompany = $this->createPeople(94);
        $requestedCompany = $this->createPeople(95);
        $deviceConfig = $this->createDeviceConfig($originalCompany, 'MANAGER', [
            OrderCommercialContextService::CHARGE_CONFIG_KEY => true,
        ]);

        $peopleRepository = $this->createMock(EntityRepository::class);
        $peopleRepository->method('find')->willReturnCallback(
            static fn(int $id): ?People => $id === 94
                ? $originalCompany
                : ($id === 95 ? $requestedCompany : null),
        );
        $deviceConfigRepository = $this->createMock(EntityRepository::class);
        $deviceConfigRepository->method('find')->with(15)->willReturn($deviceConfig);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn(string $className): EntityRepository => match ($className) {
                People::class => $peopleRepository,
                DeviceConfig::class => $deviceConfigRepository,
            },
        );
        $roleService = $this->createMock(PeopleRoleService::class);
        $roleService->method('getCompanyPermissions')->willReturnCallback(
            static fn(People $company): array => $company === $requestedCompany
                ? ['manager']
                : ['employee'],
        );
        $subscriber = new DeviceFinancialConfigAuthorizationSubscriber($manager, $roleService);

        $this->expectException(AccessDeniedHttpException::class);
        $subscriber->onKernelRequest($this->createRequestEvent($this->createRequest(
            '/device_configs/15',
            'PUT',
            [
                'people' => '/people/95',
                'type' => 'DEVICE',
                'configs' => [],
            ],
        )));
    }

    #[DataProvider('administrativePermissionProvider')]
    public function testTenantAdministrativeAuthorityCanManageProtectedConfig(string $permission): void
    {
        $company = $this->createPeople(92);
        $deviceConfig = $this->createDeviceConfig($company, 'PDV', [
            OrderCommercialContextService::CHARGE_CONFIG_KEY => false,
        ]);
        $manager = $this->buildManager($company, $deviceConfig);
        $roleService = $this->createMock(PeopleRoleService::class);
        $roleService->method('getCompanyPermissions')->with($company)->willReturn([$permission]);
        $subscriber = new DeviceFinancialConfigAuthorizationSubscriber($manager, $roleService);

        $subscriber->onKernelRequest($this->createRequestEvent($this->createRequest(
            '/device_configs/15',
            'PUT',
            [
                'people' => '/people/92',
                'type' => 'PDV',
                'configs' => [OrderCommercialContextService::CHARGE_CONFIG_KEY => true],
            ],
        )));

        self::assertTrue(true);
    }

    public static function administrativePermissionProvider(): array
    {
        return [
            'owner' => ['owner'],
            'director' => ['director'],
            'manager' => ['manager'],
            'super' => ['super'],
        ];
    }

    public function testRejectedPromotionLeavesWaiterUnableToCharge(): void
    {
        $company = $this->createPeople(93);
        $device = (new Device())->setDevice('waiter-device');
        $deviceConfig = $this->createDeviceConfig($company, 'DEVICE', [], $device);
        $manager = $this->buildManager($company, $deviceConfig, $device);
        $roleService = $this->createMock(PeopleRoleService::class);
        $roleService->method('getCompanyPermissions')->willReturn(['employee']);
        $subscriber = new DeviceFinancialConfigAuthorizationSubscriber($manager, $roleService);
        $mutation = $this->createRequest('/device_configs/15', 'PUT', [
            'people' => '/people/93',
            'type' => 'PDV',
            'configs' => [OrderCommercialContextService::CHARGE_CONFIG_KEY => true],
        ]);

        try {
            $subscriber->onKernelRequest($this->createRequestEvent($mutation));
            self::fail('Waiter should not promote its own device context.');
        } catch (AccessDeniedHttpException) {
            self::assertSame('DEVICE', $deviceConfig->getType());
            self::assertArrayNotHasKey(
                OrderCommercialContextService::CHARGE_CONFIG_KEY,
                $deviceConfig->getConfigs(true),
            );
        }

        $this->assertLocalChargeDenied($manager, $company, $deviceConfig);
    }

    private function buildManager(
        People $company,
        DeviceConfig $deviceConfig,
        ?Device $device = null,
    ): EntityManagerInterface {
        $peopleRepository = $this->createMock(EntityRepository::class);
        $peopleRepository->method('find')->willReturnCallback(
            static fn(int $id): ?People => $id === (int) $company->getId() ? $company : null,
        );
        $deviceConfigRepository = $this->createMock(EntityRepository::class);
        $deviceConfigRepository->method('find')->with(15)->willReturn($deviceConfig);
        $deviceRepository = $this->createMock(EntityRepository::class);
        $deviceRepository
            ->method('findOneBy')
            ->willReturn($device ?? $deviceConfig->getDevice());
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn(string $className): EntityRepository => match ($className) {
                People::class => $peopleRepository,
                DeviceConfig::class => $deviceConfigRepository,
                Device::class => $deviceRepository,
            },
        );

        return $manager;
    }

    private function createDeviceConfig(
        People $company,
        string $type,
        array $configs,
        ?Device $device = null,
    ): DeviceConfig {
        $deviceConfig = (new DeviceConfig())
            ->setPeople($company)
            ->setDevice($device ?? (new Device())->setDevice('device-15'))
            ->setType($type)
            ->setConfigs($configs);
        $property = new \ReflectionProperty(DeviceConfig::class, 'id');
        $property->setAccessible(true);
        $property->setValue($deviceConfig, 15);

        return $deviceConfig;
    }

    private function createPeople(int $id): People
    {
        $people = new People();
        $property = new \ReflectionProperty(People::class, 'id');
        $property->setAccessible(true);
        $property->setValue($people, $id);

        return $people;
    }

    private function createRequest(string $path, string $method, array $payload): Request
    {
        return Request::create(
            $path,
            $method,
            [],
            [],
            [],
            [],
            json_encode($payload) ?: '{}',
        );
    }

    private function assertLocalChargeDenied(
        EntityManagerInterface $manager,
        People $company,
        DeviceConfig $deviceConfig,
    ): void {
        $peopleService = $this->createMock(PeopleService::class);
        $peopleService->method('getMyCompanies')->willReturn([$company]);
        $deviceService = $this->createMock(DeviceService::class);
        $deviceService->method('findDeviceConfigs')->willReturn([$deviceConfig]);
        $requestStack = new RequestStack();
        $chargeRequest = Request::create('/invoices', 'POST');
        $chargeRequest->headers->set('DEVICE', $deviceConfig->getDevice()->getDevice());
        $requestStack->push($chargeRequest);
        $commercialContext = new OrderCommercialContextService(
            $manager,
            $peopleService,
            $deviceService,
            $requestStack,
        );

        try {
            $commercialContext->assertChargeAllowed((new Order())->setProvider($company));
            self::fail('Rejected device escalation must not enable local charge.');
        } catch (AccessDeniedHttpException) {
            self::assertTrue(true);
        }
    }

    private function createRequestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
