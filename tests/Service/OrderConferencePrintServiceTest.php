<?php

namespace {
    require_once __DIR__ . '/../Fixtures/OrderPrintDoubles.php';
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
            $orderPrintService = $this->getMockBuilder(OrderPrintService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['generatePrintDataFromPayload'])
                ->getMock();
            $orderPrintService
                ->expects(self::once())
                ->method('generatePrintDataFromPayload')
                ->with(
                    $order,
                    [
                        'device' => 'device-printer-1',
                        'type' => 'printer',
                        'displayId' => '/displays/12',
                        'source' => 'display-auto',
                    ]
                )
                ->willReturn(new Spool(77));
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
            $orderPrintService = $this->getMockBuilder(OrderPrintService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['generatePrintDataFromPayload'])
                ->getMock();
            $orderPrintService
                ->expects(self::never())
                ->method('generatePrintDataFromPayload');
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
        }
    }
}
