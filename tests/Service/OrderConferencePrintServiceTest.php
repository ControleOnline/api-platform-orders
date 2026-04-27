<?php

namespace ControleOnline\Entity {
    class People
    {
        public function __construct(private ?int $id = null) {}

        public function getId(): ?int
        {
            return $this->id;
        }
    }

    class Spool
    {
        public function __construct(private ?int $id = null) {}

        public function getId(): ?int
        {
            return $this->id;
        }
    }

    class Order
    {
        public function __construct(
            private ?int $id = null,
            private ?People $provider = null,
            private array|string|null $otherInformations = null,
        ) {}

        public function getId(): ?int
        {
            return $this->id;
        }

        public function getProvider(): ?People
        {
            return $this->provider;
        }

        public function getOtherInformations($decode = false)
        {
            if (!$decode) {
                return $this->otherInformations;
            }

            if (is_array($this->otherInformations)) {
                return (object) json_decode(json_encode($this->otherInformations));
            }

            if (is_string($this->otherInformations) && trim($this->otherInformations) !== '') {
                return json_decode($this->otherInformations) ?: new \stdClass();
            }

            return new \stdClass();
        }

        public function setOtherInformations($otherInformations): self
        {
            $normalized = json_decode(json_encode($otherInformations), true);
            $this->otherInformations = is_array($normalized) ? $normalized : [];

            return $this;
        }

        public function getStoredOtherInformations(): array
        {
            if (is_array($this->otherInformations)) {
                return $this->otherInformations;
            }

            if (is_string($this->otherInformations) && trim($this->otherInformations) !== '') {
                $decoded = json_decode($this->otherInformations, true);
                return is_array($decoded) ? $decoded : [];
            }

            return [];
        }
    }
}

namespace ControleOnline\Service {
    use ControleOnline\Entity\Order;
    use ControleOnline\Entity\Spool;

    class OrderPrintService
    {
        public array $calls = [];
        public ?Spool $spoolToReturn = null;

        public function generatePrintDataFromPayload(
            Order $order,
            array $payload
        ): ?Spool {
            $this->calls[] = [
                'order' => $order,
                'payload' => $payload,
            ];

            return $this->spoolToReturn;
        }
    }
}

namespace ControleOnline\Orders\Tests\Service {
    require_once __DIR__ . '/../../src/Service/OrderConferencePrintService.php';

    use ControleOnline\Entity\Order;
    use ControleOnline\Entity\People;
    use ControleOnline\Entity\Spool;
    use ControleOnline\Service\OrderConferencePrintService;
    use ControleOnline\Service\OrderPrintService;
    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\LockMode;
    use Doctrine\ORM\EntityManagerInterface;
    use PHPUnit\Framework\TestCase;

    class OrderConferencePrintServiceTest extends TestCase
    {
        private function createConnectionMock(): Connection
        {
            $connection = $this
                ->getMockBuilder(Connection::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['transactional'])
                ->getMock();

            $connection
                ->method('transactional')
                ->willReturnCallback(
                    fn(\Closure $callback) => $callback($connection)
                );

            return $connection;
        }

        public function testAutoPrintIfNeededMarksOrderAfterSuccessfulConferencePrint(): void
        {
            $order = new Order(321, new People(99));
            $orderPrintService = new OrderPrintService();
            $orderPrintService->spoolToReturn = new Spool(77);
            $connection = $this->createConnectionMock();

            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager
                ->method('getConnection')
                ->willReturn($connection);
            $entityManager
                ->expects(self::once())
                ->method('find')
                ->with(Order::class, 321, LockMode::PESSIMISTIC_WRITE)
                ->willReturn($order);
            $entityManager
                ->expects(self::once())
                ->method('persist')
                ->with($order);
            $entityManager
                ->expects(self::once())
                ->method('flush');

            $service = new OrderConferencePrintService(
                $entityManager,
                $orderPrintService,
            );

            $result = $service->autoPrintIfNeeded($order, [
                'device' => 'device-printer-1',
                'type' => 'printer',
                'displayId' => '/displays/12',
                'source' => 'display-auto',
            ]);

            self::assertTrue($result['printed']);
            self::assertFalse($result['alreadyPrinted']);
            self::assertSame(77, $result['spoolId']);
            self::assertSame(321, $result['orderId']);
            self::assertNotEmpty($result['printedAt']);
            self::assertCount(1, $orderPrintService->calls);

            $state = $order->getStoredOtherInformations()['conference_print'] ?? [];

            self::assertSame(true, $state['printed'] ?? null);
            self::assertSame('device-printer-1', $state['device'] ?? null);
            self::assertSame('printer', $state['device_type'] ?? null);
            self::assertSame(12, $state['display_id'] ?? null);
            self::assertSame(99, $state['people'] ?? null);
            self::assertSame(77, $state['spool_id'] ?? null);
            self::assertSame(321, $state['order_id'] ?? null);
            self::assertNotEmpty($state['printed_at'] ?? '');
        }

        public function testAutoPrintIfNeededSkipsAlreadyPrintedOrders(): void
        {
            $order = new Order(654, new People(88), [
                'conference_print' => [
                    'printed' => true,
                    'printed_at' => '2026-04-27T12:00:00+00:00',
                    'spool_id' => 44,
                ],
            ]);
            $orderPrintService = new OrderPrintService();
            $orderPrintService->spoolToReturn = new Spool(91);
            $connection = $this->createConnectionMock();

            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager
                ->method('getConnection')
                ->willReturn($connection);
            $entityManager
                ->expects(self::once())
                ->method('find')
                ->with(Order::class, 654, LockMode::PESSIMISTIC_WRITE)
                ->willReturn($order);
            $entityManager
                ->expects(self::never())
                ->method('persist');
            $entityManager
                ->expects(self::never())
                ->method('flush');

            $service = new OrderConferencePrintService(
                $entityManager,
                $orderPrintService,
            );

            $result = $service->autoPrintIfNeeded($order, [
                'device' => 'device-printer-1',
                'type' => 'printer',
            ]);

            self::assertFalse($result['printed']);
            self::assertTrue($result['alreadyPrinted']);
            self::assertSame(44, $result['spoolId']);
            self::assertSame('2026-04-27T12:00:00+00:00', $result['printedAt']);
            self::assertSame(654, $result['orderId']);
            self::assertCount(0, $orderPrintService->calls);
        }
    }
}
