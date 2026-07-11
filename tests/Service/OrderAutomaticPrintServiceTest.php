<?php

namespace {
    require_once __DIR__ . '/../Fixtures/OrderPrintDoubles.php';
}

namespace ControleOnline\Orders\Tests\Service {
    require_once __DIR__ . '/../../src/Service/OrderAutomaticPrintService.php';

    use ControleOnline\Entity\Order;
    use ControleOnline\Entity\People;
    use ControleOnline\Service\LoggerService;
    use ControleOnline\Service\OrderAutomaticPrintService;
    use ControleOnline\Service\OrderPrintService;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\LoggerInterface;

    class OrderAutomaticPrintServiceTest extends TestCase
    {
        public function testDispatchCompletedOrderPrintsKeepsPreparationPrintDisabled(): void
        {
            $provider = new People(31485);
            $order = new Order(987, $provider, 'IFOOD');

            $orderPrintService = $this->createMock(OrderPrintService::class);
            $orderPrintService
                ->expects(self::once())
                ->method('printConferenceCopies')
                ->with(
                    $order,
                    [],
                    ['automaticOrderPrint' => true]
                )
                ->willReturn(2);

            $logger = $this->createMock(LoggerInterface::class);
            $logger
                ->expects(self::once())
                ->method('info')
                ->with(
                    'Completed order automatic print dispatch finished',
                    self::callback(function (array $context): bool {
                        return $context['conferencePrinted'] === 2
                            && $context['preparationPrinted'] === 0
                            && $context['order'] === 987
                            && $context['provider'] === 31485
                            && $context['app'] === 'IFOOD';
                    })
                );

            $loggerService = $this->createMock(LoggerService::class);
            $loggerService
                ->method('getLogger')
                ->with('order-auto-print')
                ->willReturn($logger);

            $service = new OrderAutomaticPrintService(
                $orderPrintService,
                $loggerService,
            );

            self::assertSame(
                [
                    'conferencePrinted' => 2,
                    'preparationPrinted' => 0,
                ],
                $service->dispatchCompletedOrderPrints($order)
            );
        }
    }
}
