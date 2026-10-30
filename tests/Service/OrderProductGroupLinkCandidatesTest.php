<?php
namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\{Order, OrderProduct, Product, ProductGroup, ProductGroupProduct};
use ControleOnline\Service\{OrderProductGroupLinkCandidates, OrderService};
use Doctrine\ORM\{EntityManagerInterface, EntityRepository, Query, QueryBuilder};
use PHPUnit\Framework\TestCase;

final class OrderProductGroupLinkCandidatesTest extends TestCase
{
    private function manager(int $queries, array $results): EntityManagerInterface
    {
        $query = $this->createMock(Query::class);
        $query->expects(self::exactly($queries))->method('getResult')->willReturn($results);
        $builder = $this->createMock(QueryBuilder::class);
        foreach (['andWhere', 'setParameter', 'innerJoin'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('getQuery')->willReturn($query);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($builder);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->with(ProductGroupProduct::class)->willReturn($repository);
        $manager->expects(self::never())->method('flush');
        return $manager;
    }

    public function testRepeatedPairsAndEmptyResultsReadOncePerPass(): void
    {
        $manager = $this->manager(4, []);
        $parent = new Product();
        $child = new Product();
        $otherChild = new Product();
        $cache = new OrderProductGroupLinkCandidates($manager);
        for ($i = 0; $i < 10; $i++) self::assertSame([], $cache->get($parent, $child));
        self::assertSame([], $cache->get($parent, $otherChild));
    }

    public function testAnotherPassReadsTheCatalogAgain(): void
    {
        $manager = $this->manager(4, []);
        $parent = new Product();
        $child = new Product();
        self::assertSame([], (new OrderProductGroupLinkCandidates($manager))->get($parent, $child));
        self::assertSame([], (new OrderProductGroupLinkCandidates($manager))->get($parent, $child));
    }

    public function testNormalizationReusesReadsButPreservesEachCurrentGroup(): void
    {
        $order = new Order();
        $parent = new Product();
        $child = new Product();
        $groups = [new ProductGroup(), new ProductGroup()];
        $links = [];
        foreach ($groups as $index => $group) {
            $this->id($group, $index + 1);
            $link = (new ProductGroupProduct())->setProduct($parent)->setProductChild($child)
                ->setProductGroup($group)->setShowInParentQueue(true);
            $this->id($link, $index + 1);
            $links[] = $link;
        }
        $root = (new OrderProduct())->setProduct($parent)->setOrder($order);
        $this->id($root, 10);
        $order->addOrderProduct($root);
        $lines = [];
        foreach ($groups as $index => $group) {
            $line = (new OrderProduct())->setProduct($child)->setParentProduct($parent)
                ->setProductGroup($group)->setOrderProduct($root)->setOrder($order)->setShowInParentQueue(true);
            $this->id($line, 11 + $index);
            $order->addOrderProduct($line);
            $lines[] = $line;
        }
        $manager = $this->manager(4, $links);
        $service = (new \ReflectionClass(OrderService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(OrderService::class, 'manager'))->setValue($service, $manager);
        for ($i = 0; $i < 2; $i++) {
            self::assertFalse($service->normalizeOrderProductGroupLinks($order));
            foreach ($lines as $index => $line) {
                self::assertSame($groups[$index], $line->getProductGroup());
                self::assertSame($root, $line->getOrderProduct());
            }
        }
    }

    private function id(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
