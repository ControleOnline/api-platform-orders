<?php
declare(strict_types=1);
use ApiPlatform\Metadata\GetCollection;
use ControleOnline\Entity\Order;
use ControleOnline\Repository\OrderRepository;
use ControleOnline\Service\OrderSalesSummaryResolver;
use ControleOnline\Service\PeopleService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class OperationalOrderSummaryTest extends TestCase
{
    public function testReusesFilteredIdsAndParametersForTheCompleteStatusCounts(): void
    {
        $ids = $this->createMock(QueryBuilder::class);
        $ids->method('getDQL')->willReturn('SELECT DISTINCT scoped.id FROM ControleOnline\\Entity\\Order scoped WHERE scoped.provider = :tenant');
        $ids->method('getParameters')->willReturn(new ArrayCollection([new Parameter('tenant',3)]));
        $query = $this->createMock(Query::class);
        $query->method('getArrayResult')->willReturn([
            ['id'=>1,'status'=>'Open','color'=>'#123456','quantity'=>'47'],
            ['id'=>2,'status'=>'Preparing','color'=>'#654321','quantity'=>'20'],
        ]);
        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select','innerJoin','groupBy','orderBy','from'] as $method) $qb->method($method)->willReturnSelf();
        $qb->expects(self::once())->method('andWhere')->with('operational_order.id IN ('.$ids->getDQL().')')->willReturnSelf();
        $qb->expects(self::once())->method('setParameter')->with('tenant',3)->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);
        $em->method('getClassMetadata')->willReturn(new ClassMetadata(Order::class));
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $registry->method('getManager')->willReturn($em);
        $repository = new OrderRepository($this->createMock(PeopleService::class),$registry);
        $summary = $repository->resolveOperationalStatusSummary($ids);
        self::assertSame(67,$summary['total']);
        self::assertSame(47,$summary['statuses'][0]['quantity']);
    }
    public function testOperationalSummaryDoesNotInvokeFinancialSalesSummary(): void
    {
        $ids = $this->createMock(QueryBuilder::class);
        $repository = $this->createMock(OrderRepository::class);
        $repository->expects(self::never())->method('resolveSalesSummary');
        $repository->expects(self::once())->method('resolveOperationalStatusSummary')->with($ids)->willReturn(['total'=>0,'statuses'=>[]]);
        self::assertSame(['total'=>0,'statuses'=>[]],(new OrderSalesSummaryResolver($repository))->resolve(new GetCollection(class:Order::class),Order::class,['name'=>'operational'],$ids));
    }
}
