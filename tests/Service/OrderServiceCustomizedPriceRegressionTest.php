<?php
namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\{Order, OrderProduct, Product, ProductGroup, ProductGroupProduct};
use ControleOnline\Service\{OrderProductTreePriceCalculator, OrderService};
use Doctrine\DBAL\{Connection, Statement};
use Doctrine\ORM\{EntityManagerInterface, EntityRepository, Query, QueryBuilder};
use PHPUnit\Framework\TestCase;

final class OrderServiceCustomizedPriceRegressionTest extends TestCase
{
    public function testCustomComponentsUseRealPriceCalculationAndReuseReadsWithinEachPass(): void
    {
        [$service, $order, $components] = $this->fixture(true);
        for ($i = 0; $i < 2; $i++) {
            self::assertSame($order, $service->calculateGroupProductPrice($order));
            foreach ($components as $component) {
                self::assertSame(3.0, (float) $component->getPrice());
                self::assertSame(6.0, (float) $component->getTotal());
            }
        }
    }

    public function testMissingCatalogLinkKeepsTheExistingComponentPrice(): void
    {
        [$service, $order, $components] = $this->fixture(false);
        for ($i = 0; $i < 2; $i++) {
            self::assertSame($order, $service->calculateGroupProductPrice($order));
            foreach ($components as $component) {
                self::assertSame(9.0, (float) $component->getPrice());
                self::assertSame(18.0, (float) $component->getTotal());
            }
        }
    }

    private function fixture(bool $hasLink): array
    {
        $order = new Order();
        $parent = new Product();
        $child = new Product();
        $group = (new ProductGroup())->setPriceCalculation('sum');
        (new \ReflectionProperty(ProductGroup::class, 'id'))->setValue($group, 1);
        $link = (new ProductGroupProduct())->setProduct($parent)->setProductChild($child)
            ->setProductGroup($group)->setPrice(3)->setShowInParentQueue(true);
        $components = [];
        for ($i = 0; $i < 2; $i++) {
            $root = (new OrderProduct())->setOrder($order)->setProduct($parent)->setQuantity(2)->setPrice(73);
            $component = (new OrderProduct())->setOrder($order)->setProduct($child)->setProductGroup($group)
                ->setOrderProduct($root)->setParentProduct($parent)->setQuantity(2)->setPrice(9);
            $root->addOrderProductComponent($component);
            $order->addOrderProduct($root);
            $order->addOrderProduct($component);
            $components[] = $component;
        }
        $query = $this->createMock(Query::class);
        $query->expects(self::exactly(4))->method('getResult')->willReturn($hasLink ? [$link] : []);
        $builder = $this->createMock(QueryBuilder::class);
        foreach (['andWhere', 'setParameter', 'innerJoin'] as $method) $builder->method($method)->willReturnSelf();
        $builder->method('getQuery')->willReturn($query);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($builder);
        $statement = $this->createMock(Statement::class);
        $statement->expects(self::exactly(2))->method('executeStatement')->willReturn(1);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('prepare')
            ->with(self::callback(fn ($sql) => str_contains($sql, 'UPDATE order_product')))->willReturn($statement);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->with(ProductGroupProduct::class)->willReturn($repository);
        $manager->method('getConnection')->willReturn($connection);
        $manager->expects(self::exactly(2))->method('flush');
        $service = (new \ReflectionClass(OrderService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(OrderService::class, 'manager'))->setValue($service, $manager);
        (new \ReflectionProperty(OrderService::class, 'treePriceCalculator'))
            ->setValue($service, new OrderProductTreePriceCalculator());
        return [$service, $order, $components];
    }
}
