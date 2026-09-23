<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\PaymentType;
use ControleOnline\Entity\Status;
use ControleOnline\Service\MarkOrderAsPaidService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\StatusService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class MarkOrderAsPaidSettlementTest extends TestCase
{
    public function testSettlementUsesFullOutstandingBalanceAndClosesOrder(): void
    {
        $order = new Order();
        $order->setProvider(new People());
        $order->setStatus($this->createStatusEntity('open', 'open'));
        $order->setPrice(125.00);

        $paymentType = (new PaymentType())->setId(9);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->with(9)->willReturn($paymentType);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->method('isTransactionActive')->willReturn(false);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getRepository')->with(PaymentType::class)->willReturn($repository);
        $manager->expects(self::atLeast(2))->method('flush');
        $manager->expects(self::once())->method('refresh')->with($order);

        $paidInvoice = $this->createStatusEntity('closed', 'paid');
        $paidOrder = $this->createStatusEntity('closed', 'paid');
        $statuses = $this->createMock(StatusService::class);
        $statuses->method('discoveryStatus')->willReturnCallback(
            static fn (string $realStatus, string $status, string $context): ?Status =>
                $context === 'invoice' ? $paidInvoice : $paidOrder
        );

        $service = new MarkOrderAsPaidService(
            $manager,
            $this->createMock(PeopleService::class),
            $statuses,
        );

        $result = $service->markAsPaid($order, [
            'paymentType' => 9,
            'price' => 1.00,
        ], $order->getProvider());

        self::assertSame('paid', $result['order']['status']);
        self::assertSame('closed', $result['order']['realStatus']);
        self::assertSame(0.0, $result['order']['balance']);
        self::assertSame(125.0, $result['invoice']['realPrice']);
    }

    private function createStatusEntity(string $realStatus, string $status): Status
    {
        return (new Status())
            ->setRealStatus($realStatus)
            ->setStatus($status);
    }
}
