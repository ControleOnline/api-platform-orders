<?php

namespace ControleOnline\Repository;

use ControleOnline\Entity\Order;
use ControleOnline\Service\PeopleService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;


class OrderRepository extends ServiceEntityRepository
{
  public function __construct(
    private PeopleService $peopleService,

    ManagerRegistry $registry
  ) {
    parent::__construct($registry, Order::class);
  }

  public function resolveReportSummaryTotals(QueryBuilder $filteredIdsQueryBuilder): array
  {
    $row = $this->fetchReportSummaryRow(
      $this->createReportSummaryQueryBuilder($filteredIdsQueryBuilder, 'summary_order')
        ->select(
          'COUNT(DISTINCT summary_order.id) AS totalOrders',
          'COALESCE(SUM(summary_order_product.quantity), 0) AS totalUnits'
        )
    );

    return [
      'orders' => (int) ($row['totalOrders'] ?? 0),
      'units' => (float) ($row['totalUnits'] ?? 0),
    ];
  }

  public function resolveReportSummaryApps(QueryBuilder $filteredIdsQueryBuilder): array
  {
    $rows = $this->fetchReportSummaryRows(
      $this->createReportSummaryQueryBuilder($filteredIdsQueryBuilder, 'summary_order')
        ->select(
          'summary_order.app AS appName',
          'COUNT(DISTINCT summary_order.id) AS totalOrders',
          'COALESCE(SUM(summary_order_product.quantity), 0) AS totalUnits'
        )
        ->groupBy('summary_order.app')
        ->orderBy('totalUnits', 'DESC')
        ->addOrderBy('totalOrders', 'DESC')
    );

    return array_values(array_map(function (array $row): array {
      $appName = $this->normalizeReportText($row['appName'] ?? null);
      $appLabel = '' !== $appName ? $appName : 'POS';

      return [
        'key' => $appLabel,
        'label' => $appLabel,
        'orders' => (int) ($row['totalOrders'] ?? 0),
        'units' => (float) ($row['totalUnits'] ?? 0),
      ];
    }, $rows));
  }

  public function resolveReportSummaryDisplays(QueryBuilder $filteredIdsQueryBuilder): array
  {
    $rows = $this->fetchReportSummaryRows(
      $this->createReportSummaryQueryBuilder($filteredIdsQueryBuilder, 'summary_order')
        ->join('summary_order_product.orderProductQueues', 'summary_queue_entry')
        ->join('summary_queue_entry.queue', 'summary_queue')
        ->join('summary_queue.displayQueue', 'summary_display_queue')
        ->join('summary_display_queue.display', 'summary_display')
        ->select(
          'summary_display.id AS displayId',
          'summary_display.display AS displayName',
          'COUNT(DISTINCT summary_order.id) AS totalOrders',
          'COUNT(DISTINCT summary_queue.id) AS queueCount',
          'COALESCE(SUM(summary_order_product.quantity), 0) AS totalUnits'
        )
        ->groupBy('summary_display.id, summary_display.display')
        ->orderBy('totalUnits', 'DESC')
        ->addOrderBy('totalOrders', 'DESC')
    );

    return array_values(array_map(function (array $row): array {
      $displayName = $this->normalizeReportText($row['displayName'] ?? null);
      $displayLabel = '' !== $displayName ? $displayName : 'Sem display';

      return [
        'displayId' => (int) ($row['displayId'] ?? 0),
        'key' => $displayLabel,
        'label' => $displayLabel,
        'orders' => (int) ($row['totalOrders'] ?? 0),
        'queueCount' => (int) ($row['queueCount'] ?? 0),
        'units' => (float) ($row['totalUnits'] ?? 0),
      ];
    }, $rows));
  }

  public function resolveReportTopProducts(QueryBuilder $filteredIdsQueryBuilder, int $limit = 8): array
  {
    $rows = $this->fetchReportSummaryRows(
      $this->createReportSummaryQueryBuilder($filteredIdsQueryBuilder, 'summary_order')
        ->join('summary_order_product.product', 'summary_product')
        ->select(
          'summary_product.id AS productId',
          'summary_product.product AS productName',
          'COUNT(DISTINCT summary_order.id) AS totalOrders',
          'COALESCE(SUM(summary_order_product.quantity), 0) AS totalUnits'
        )
        ->groupBy('summary_product.id, summary_product.product')
        ->orderBy('totalUnits', 'DESC')
        ->addOrderBy('totalOrders', 'DESC')
        ->setMaxResults($limit)
    );

    return array_values(array_map(function (array $row): array {
      $productName = $this->normalizeReportText($row['productName'] ?? null);

      return [
        'productId' => (int) ($row['productId'] ?? 0),
        'key' => $productName,
        'label' => $productName,
        'orders' => (int) ($row['totalOrders'] ?? 0),
        'units' => (float) ($row['totalUnits'] ?? 0),
      ];
    }, $rows));
  }

  private function createReportSummaryQueryBuilder(
    QueryBuilder $filteredIdsQueryBuilder,
    string $alias
  ): QueryBuilder {
    $queryBuilder = $this->createQueryBuilder($alias);
    $queryBuilder
      ->join(sprintf('%s.orderProducts', $alias), 'summary_order_product')
      ->andWhere(sprintf('%s.id IN (%s)', $alias, $filteredIdsQueryBuilder->getDQL()))
      ->andWhere('summary_order_product.orderProduct IS NULL');

        $this->copyReportParameters($queryBuilder, $filteredIdsQueryBuilder);

    return $queryBuilder;
  }

  private function fetchReportSummaryRow(QueryBuilder $queryBuilder): array
  {
    $rows = $this->fetchReportSummaryRows($queryBuilder);

    return $rows[0] ?? [];
  }

  private function fetchReportSummaryRows(QueryBuilder $queryBuilder): array
  {
    $result = $queryBuilder->getQuery()->getArrayResult();

    return array_values(array_filter(
      is_array($result) ? $result : [],
      static fn ($row) => is_array($row)
    ));
  }

  private function copyReportParameters(QueryBuilder $targetQueryBuilder, QueryBuilder $sourceQueryBuilder): void
  {
    $parameters = $sourceQueryBuilder->getParameters();

    if (!$parameters instanceof Collection && !is_array($parameters)) {
      return;
    }

    foreach ($parameters as $parameter) {
      if (!$parameter instanceof Parameter) {
        continue;
      }

      if ($parameter->typeWasSpecified()) {
        $targetQueryBuilder->setParameter(
          $parameter->getName(),
          $parameter->getValue(),
          $parameter->getType()
        );

        continue;
      }

      $targetQueryBuilder->setParameter($parameter->getName(), $parameter->getValue());
    }
  }

  private function normalizeReportText(mixed $value): string
  {
    return trim((string) $value);
  }
}
