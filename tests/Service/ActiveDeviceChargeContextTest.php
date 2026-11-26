<?php

declare(strict_types=1);

use ControleOnline\Entity\{Device, DeviceConfig, People};
use ControleOnline\Service\{DeviceService, OrderCommercialContextService, PeopleService};
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\{AllowMockObjectsWithoutExpectations, DataProvider};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{Request, RequestStack};

#[AllowMockObjectsWithoutExpectations]
final class ActiveDeviceChargeContextTest extends TestCase
{
    public static function contexts(): iterable
    {
        yield 'POS selects its persisted permission' => ['PDV', ['PDV' => true, 'MANAGER' => null], true, true];
        yield 'existing Manager remote permission retained with unconfigured Manager row' => ['MANAGER', ['PDV' => true, 'MANAGER' => null], false, true];
        yield 'Manager remains remote only' => ['MANAGER', ['PDV' => true, 'MANAGER' => true], false, true];
        yield 'ambiguous legacy request stays closed' => ['', ['PDV' => true, 'MANAGER' => true], false, true];
        yield 'header cannot invent POS permission' => ['PDV', ['MANAGER' => true], false, true];
        yield 'disabled POS remains disabled' => ['PDV', ['PDV' => false, 'MANAGER' => true], false, false];
        yield 'missing POS permission stays closed' => ['PDV', ['PDV' => null], false, false];
        yield 'shop cannot inherit POS permission' => ['SHOP', ['PDV' => true, 'SHOP' => true], false, false];
        yield 'unknown context cannot inherit POS permission' => ['OTHER', ['PDV' => true], false, false];
        yield 'unambiguous legacy POS preserved' => ['', ['PDV' => true], true, true];
        yield 'waiter POS capability remains explicit' => ['PDV', ['PDV' => true], true, true];
    }

    #[DataProvider('contexts')]
    public function testUsesPersistedCapabilityOfActiveContext(string $type, array $rows, bool $local, bool $enabled): void
    {
        $company = new People();
        $device = (new Device())->setDevice('test-device');
        $configs = [];
        foreach ($rows as $rowType => $permission) {
            $configs[] = (new DeviceConfig())->setDevice($device)->setPeople($company)->setType($rowType)
                ->setConfigs($permission === null ? [] : ['order-charge-enabled' => $permission]);
        }
        $deviceService = $this->createMock(DeviceService::class);
        $deviceService->method('findDeviceConfigs')->with($device, $company)->willReturn($configs);
        $request = Request::create('/invoices', 'POST');
        if ($type !== '') $request->headers->set('DEVICE-TYPE', $type);
        $stack = new RequestStack();
        $stack->push($request);
        $service = new OrderCommercialContextService($this->createMock(EntityManagerInterface::class),
            $this->createMock(PeopleService::class), $deviceService, $stack);
        $capability = (new ReflectionMethod($service, 'resolveConfiguredChargeCapability'))->invoke($service, $device, $company);
        self::assertSame($local, $capability['local']);
        self::assertSame($enabled, $capability['enabled']);
    }
}
