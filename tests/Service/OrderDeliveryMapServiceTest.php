<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Address;
use ControleOnline\Entity\Cep;
use ControleOnline\Entity\City;
use ControleOnline\Entity\District;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\State;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\Street;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\OrderDeliveryMapService;
use ControleOnline\Service\PeopleService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class OrderDeliveryMapServiceTest extends TestCase
{
    public function testBuildPayloadReturnsDisabledWhenCompanyHasNoGoogleMapsKey(): void
    {
        $provider = $this->people(77, 'Gyros Franquias', 'Gyros');

        $peopleRepository = $this->createMock(EntityRepository::class);
        $peopleRepository
            ->expects(self::once())
            ->method('find')
            ->with(77)
            ->willReturn($provider);

        $configService = $this->createMock(ConfigService::class);
        $configService
            ->expects(self::once())
            ->method('getConfig')
            ->with($provider, OrderDeliveryMapService::GOOGLE_MAPS_API_KEY_CONFIG_KEY)
            ->willReturn(null);

        $peopleService = $this->createMock(PeopleService::class);
        $peopleService
            ->method('getMyPeople')
            ->willReturn($this->people(10, 'Operador', 'Operador'));
        $peopleService
            ->method('canAccessCompany')
            ->with($provider, self::isInstanceOf(People::class))
            ->willReturn(true);

        $service = new OrderDeliveryMapService(
            $this->manager([People::class => $peopleRepository]),
            $configService,
            $peopleService,
        );

        $payload = $service->buildPayload('/people/77', '2026-05-13');

        self::assertFalse($payload['enabled']);
        self::assertSame('', $payload['googleMapsApiKey']);
        self::assertSame('2026-05-13', $payload['date']);
        self::assertSame([], $payload['deliveries']);
        self::assertSame(0, $payload['totalDeliveries']);
    }

    public function testNormalizeDeliveryOrderFormatsAddressAndDisplayCode(): void
    {
        $order = new Order();
        $this->setEntityId($order, 71107);
        $this->setDateProperty($order, 'orderDate', new DateTimeImmutable('2026-05-13 19:00:00'));
        $order->setAlterDate(new DateTimeImmutable('2026-05-13 19:30:00'));
        $order->setOrderType(Order::ORDER_TYPE_SALE);
        $order->setApp('Food99');
        $order->setPrice(78.99);
        $order->setClient($this->people(31, 'Paula Cliente', 'Paula'));
        $order->setStatus($this->orderStatus(843, 'way', 'pending', '#0EA5E9'));
        $order->setAddressDestination($this->address());
        $order->setOtherInformations([
            'order_info' => [
                'order_index' => '570002',
            ],
        ]);

        $delivery = $this->service()->normalizeDeliveryOrder($order);

        self::assertSame(71107, $delivery['id']);
        self::assertSame('/orders/71107', $delivery['@id']);
        self::assertSame('Food99', $delivery['app']);
        self::assertSame('570002', $delivery['displayCode']);
        self::assertSame('way', $delivery['status']['status']);
        self::assertSame('PAULA CLIENTE', $delivery['client']['name']);
        self::assertSame('RUA TESTE, 123 - CENTRO - SAO PAULO / SP - 01234567', $delivery['address']['formatted']);
        self::assertSame(-23.55, $delivery['address']['latitude']);
        self::assertSame(-46.63, $delivery['address']['longitude']);
    }

    private function service(): OrderDeliveryMapService
    {
        return new OrderDeliveryMapService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ConfigService::class),
            $this->createMock(PeopleService::class),
        );
    }

    /**
     * @param array<class-string, EntityRepository> $repositories
     */
    private function manager(array $repositories): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->method('getRepository')
            ->willReturnCallback(fn(string $class) => $repositories[$class]);

        return $manager;
    }

    private function address(): Address
    {
        $state = new State();
        $state->setState('Sao Paulo');
        $state->setUf('SP');

        $city = new City();
        $city->setCity('Sao Paulo');
        $city->setState($state);

        $district = new District();
        $district->setDistrict('Centro');
        $district->setCity($city);

        $cep = new Cep();
        $cep->setCep(1234567);

        $street = new Street();
        $street->setStreet('Rua Teste');
        $street->setDistrict($district);
        $street->setCep($cep);

        $address = new Address();
        $this->setEntityId($address, 900);
        $address->setNickname('Casa');
        $address->setNumber(123);
        $address->setComplement('Apto 10');
        $address->setStreet($street);
        $address->setLatitude(-23.55);
        $address->setLongitude(-46.63);

        return $address;
    }

    private function people(int $id, string $name, string $alias): People
    {
        $people = new People();
        $this->setEntityId($people, $id);
        $people->setName($name);
        $people->setAlias($alias);

        return $people;
    }

    private function orderStatus(int $id, string $status, string $realStatus, string $color): Status
    {
        $entityStatus = new Status();
        $this->setEntityId($entityStatus, $id);
        $entityStatus->setStatus($status);
        $entityStatus->setRealStatus($realStatus);
        $entityStatus->setColor($color);
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

    private function setDateProperty(Order $order, string $propertyName, DateTimeImmutable $value): void
    {
        $property = new \ReflectionProperty($order, $propertyName);
        $property->setAccessible(true);
        $property->setValue($order, $value);
    }
}
