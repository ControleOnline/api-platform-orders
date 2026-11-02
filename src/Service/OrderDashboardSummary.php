<?php
namespace ControleOnline\Service;

use ControleOnline\Repository\OrderRepository;
use Doctrine\ORM\QueryBuilder;

/** Read-only summaries reuse the collection's authorized, filtered order IDs. */
final class OrderDashboardSummary
{
  public function __construct(private OrderRepository $orders) {}

  /** Count the filtered collection, without introducing sales/status/product criteria. */
  public function resolveMovementSummary(QueryBuilder $filteredIdsQueryBuilder): array
  {
    $qb = $this->orders->createQueryBuilder('movement_order');
    $qb->select('movement_order.id AS orderId', 'movement_order.orderDate AS orderDate')
      ->andWhere(sprintf('movement_order.id IN (%s)', $filteredIdsQueryBuilder->getDQL()));
    $this->copyReportParameters($qb, $filteredIdsQueryBuilder);
    $rows = $qb->getQuery()->getArrayResult();
    $daily = $monthly = [];
    $undated = 0;
    foreach ($rows as $row) {
      $date = $this->normalizeReportDateKey($row['orderDate'] ?? null);
      if ($date === '') { ++$undated; continue; }
      $month = substr($date, 0, 7);
      $daily[$date] = ($daily[$date] ?? 0) + 1;
      $monthly[$month] = ($monthly[$month] ?? 0) + 1;
    }
    ksort($daily); ksort($monthly);
    $series = static function (array $groups, bool $monthly): array {
      $result = [];
      foreach ($groups as $key => $orders) {
        $result[] = ['key'=>$key, 'label'=>$monthly ? substr($key,5,2).'/'.substr($key,0,4) : substr($key,8,2).'/'.substr($key,5,2), 'orders'=>$orders];
      }
      return $result;
    };
    return ['totals'=>['orders'=>count($rows),'undatedOrders'=>$undated],
      'daily'=>$series($daily,false),'monthly'=>$series($monthly,true)];
  }


  /** Order face value marked paid, not invoice receipts or proof of service fulfillment.
   * Explicit opt-in summary: the existing sales summary keeps its original contract.
   */
  public function resolvePaidOrdersSummary(QueryBuilder $filteredIdsQueryBuilder): array
  {
    $qb = $this->orders->createQueryBuilder('paid_order');
    $qb->select('COUNT(paid_order.id) AS orders', 'SUM(paid_order.price) AS amount',
        'SUM(CASE WHEN paid_order.price IS NULL THEN 1 ELSE 0 END) AS unpricedOrders')
      ->join('paid_order.status', 'paid_status')
      ->andWhere(sprintf('paid_order.id IN (%s)', $filteredIdsQueryBuilder->getDQL()))
      ->andWhere('paid_status.status = :valuePaidStatus')
      ->andWhere('paid_status.realStatus = :valuePaidRealStatus');
    $this->copyReportParameters($qb, $filteredIdsQueryBuilder);
    $qb->setParameter('valuePaidStatus', 'paid')->setParameter('valuePaidRealStatus', 'open');
    $row = $qb->getQuery()->getSingleResult();
    return ['basis'=>'order-price', 'status'=>'paid', 'realStatus'=>'open',
      'totals'=>['orders'=>(int)$row['orders'], 'amount'=>round((float)($row['amount'] ?? 0), 2),
        'unpricedOrders'=>(int)($row['unpricedOrders'] ?? 0)]];
  }

  private function copyReportParameters(QueryBuilder $target, QueryBuilder $source): void
  {
    foreach ($source->getParameters() as $parameter) {
      $target->setParameter($parameter->getName(), $parameter->getValue(), $parameter->getType());
    }
  }

  private function normalizeReportDateKey(mixed $value): string
  {
    if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : '';
  }
}
