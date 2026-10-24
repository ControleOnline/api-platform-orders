<?php
namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\{Order, OrderProduct, Product};
use ControleOnline\Service\OrderDetailsReadLoader;
use Doctrine\ORM\{EntityManagerInterface, EntityRepository, Query, QueryBuilder};
use PHPUnit\Framework\TestCase;

final class OrderDetailsReadLoaderTest extends TestCase
{
    public function testReadsCollectionsSeparatelyAndPreservesNestedLines(): void
    {
        $order = new Order();
        $this->id($order, 40);
        $parent = null;
        for ($i = 0; $i < 20; $i++) {
            $product = new Product();
            $this->id($product, 100 + ($i % 5));
            $line = (new OrderProduct())->setOrder($order)->setProduct($product)->setQuantity(2)->setPrice(7)->setTotal(14);
            $this->id($line, $i + 1);
            if ($parent) $line->setOrderProduct($parent);
            else $parent = $line;
            $order->addOrderProduct($line);
        }
        $queries = [];
        $config = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration(glob(dirname(__DIR__, 3).'/*/src/Entity', GLOB_ONLYDIR), true);
        $config->enableNativeLazyObjects(true);
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0.37']);
        $compiler = new \Doctrine\ORM\EntityManager($connection, $config);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('flush');
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::exactly(3))->method('getRepository')->willReturnCallback(function ($class) use (&$queries, $compiler) {
            $query = $this->createMock(Query::class);
            $query->expects(self::once())->method('getResult')->willReturn([]);
            $builder = $this->createMock(QueryBuilder::class);
            $joins = [];
            $params = [];
            $select = [];
            $where = "";
            $alias = "";
            $builder->method('select')->willReturnCallback(function (...$args) use (&$select, $builder) {$select = $args; return $builder;});
            $builder->method('leftJoin')->willReturnCallback(function ($association, $alias) use (&$joins, $builder) {$joins[$association] = $alias; return $builder;});
            $builder->method('andWhere')->willReturnCallback(function ($value) use (&$where, $builder) {$where = $value; return $builder;});
            $builder->method('setParameter')->willReturnCallback(function ($key, $value) use (&$params, $builder) {$params[$key] = $value; return $builder;});
            $builder->method('getQuery')->willReturnCallback(function () use (&$queries, &$joins, &$params, &$select, &$where, &$alias, $class, $query, $compiler) {
                $dql = 'SELECT '.implode(', ', $select).' FROM '.$class.' '.$alias;
                foreach ($joins as $association => $joinAlias) $dql .= ' LEFT JOIN '.$association.' '.$joinAlias;
                $sql = $compiler->createQuery($dql.' WHERE '.$where)->getSQL();
                self::assertIsString($sql);
                $queries[] = compact('class', 'joins', 'params', 'select'); return $query;
            });
            $repository = $this->createMock(EntityRepository::class);
            $repository->method('createQueryBuilder')->willReturnCallback(function ($value) use (&$alias, $builder) {$alias = $value; return $builder;});
            return $repository;
        });
        (new OrderDetailsReadLoader($manager))->load($order);
        self::assertFalse($connection->isConnected());
        self::assertSame([Order::class, Product::class, OrderProduct::class], array_column($queries, 'class'));
        self::assertSame(40, $queries[0]['params']['orderId']);
        self::assertSame([100, 101, 102, 103, 104], $queries[1]['params']['productIds']);
        self::assertSame($order, $queries[2]['params']['detailOrder']);
        self::assertArrayHasKey('detailOrder.orderProducts', $queries[0]['joins']);
        self::assertArrayHasKey('detailProduct.productFiles', $queries[1]['joins']);
        self::assertArrayNotHasKey('productFile.file', $queries[1]['joins']); // Never hydrate binary files.
        self::assertArrayHasKey('detailLine.orderProductQueues', $queries[2]['joins']);
        foreach ($order->getOrderProducts() as $index => $line) {
            self::assertSame(2.0, (float) $line->getQuantity());
            self::assertSame(14.0, (float) $line->getTotal());
            if ($index) self::assertSame($parent, $line->getOrderProduct());
        }
    }

    public function testUnsavedOrderDoesNotRead(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getRepository');
        (new OrderDetailsReadLoader($manager))->load(new Order());
    }

    public function testEmptyOrderDoesNotReadFilesOrQueues(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('getResult')->willReturn([]);
        $builder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'leftJoin', 'andWhere', 'setParameter'] as $method) $builder->method($method)->willReturnSelf();
        $builder->method('getQuery')->willReturn($query);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('createQueryBuilder')->willReturn($builder);
        $manager->expects(self::once())->method('getRepository')->with(Order::class)->willReturn($repo);
        $order = new Order(); $this->id($order, 40);
        (new OrderDetailsReadLoader($manager))->load($order);
    }

    private function id(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
