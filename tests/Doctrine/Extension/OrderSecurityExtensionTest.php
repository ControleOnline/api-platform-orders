<?php

namespace ControleOnline\Tests\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ControleOnline\Doctrine\Extension\OrderSecurityExtension;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Service\OrderService;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class OrderSecurityExtensionTest extends TestCase
{
    public function testAppliesSecurityFilterToOrderCollection(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRootAliases')->willReturn(['orders']);
        $queryNameGenerator = $this->createStub(QueryNameGeneratorInterface::class);

        $orderService
            ->expects(self::once())
            ->method('securityFilter')
            ->with($queryBuilder, Order::class, 'api_platform', 'orders');

        $extension = new OrderSecurityExtension($orderService);
        $extension->applyToCollection(
            $queryBuilder,
            $queryNameGenerator,
            Order::class
        );
    }

    public function testAppliesSecurityFilterToOrderItem(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRootAliases')->willReturn(['orders']);
        $queryNameGenerator = $this->createStub(QueryNameGeneratorInterface::class);

        $orderService
            ->expects(self::once())
            ->method('securityFilter')
            ->with($queryBuilder, Order::class, 'api_platform', 'orders');

        $extension = new OrderSecurityExtension($orderService);
        $extension->applyToItem(
            $queryBuilder,
            $queryNameGenerator,
            Order::class,
            ['id' => 1]
        );
    }

    public function testIgnoresOtherResources(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryNameGenerator = $this->createStub(QueryNameGeneratorInterface::class);

        $orderService
            ->expects(self::never())
            ->method('securityFilter');

        $extension = new OrderSecurityExtension($orderService);
        $extension->applyToCollection(
            $queryBuilder,
            $queryNameGenerator,
            Invoice::class
        );
    }
}
