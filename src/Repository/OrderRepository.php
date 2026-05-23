<?php

namespace ControleOnline\Repository;

use ControleOnline\Entity\Order;
use ControleOnline\Service\PeopleService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use DateTimeInterface;


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

  public function resolveOperationalInsights(QueryBuilder $filteredIdsQueryBuilder): array
  {
    $rows = $this->fetchOperationalInsightsRows($filteredIdsQueryBuilder);

    $products = $this->buildOperationalInsightsProducts($rows);

    return [
      'totals' => $this->buildOperationalInsightsTotals($rows),
      'apps' => $this->buildOperationalInsightsApps($rows),
      'displays' => $this->resolveReportSummaryDisplays($filteredIdsQueryBuilder),
      'daily' => $this->buildOperationalInsightsDaily($rows),
      'products' => $products,
      'abc' => $this->buildOperationalInsightsAbc($products),
    ];
  }

  public function resolveOperationalInsight(
    QueryBuilder $filteredIdsQueryBuilder,
    string $insight
  ): array {
    $normalizedInsight = strtolower($this->normalizeReportText($insight));

    if ('' === $normalizedInsight) {
      return $this->resolveOperationalInsights($filteredIdsQueryBuilder);
    }

    return match ($normalizedInsight) {
      'totals' => [
        'totals' => $this->buildOperationalInsightsTotals(
          $this->fetchOperationalInsightsRows($filteredIdsQueryBuilder)
        ),
      ],
      'apps' => [
        'apps' => $this->buildOperationalInsightsApps(
          $this->fetchOperationalInsightsRows($filteredIdsQueryBuilder)
        ),
      ],
      'displays' => [
        'displays' => $this->resolveReportSummaryDisplays($filteredIdsQueryBuilder),
      ],
      'products' => [
        'products' => $this->buildOperationalInsightsProducts(
          $this->fetchOperationalInsightsRows($filteredIdsQueryBuilder)
        ),
      ],
      'daily' => [
        'daily' => $this->buildOperationalInsightsDaily(
          $this->fetchOperationalInsightsRows($filteredIdsQueryBuilder)
        ),
      ],
      'abc' => [
        'abc' => $this->buildOperationalInsightsAbc(
          $this->buildOperationalInsightsProducts(
            $this->fetchOperationalInsightsRows($filteredIdsQueryBuilder)
          )
        ),
      ],
      default => $this->resolveOperationalInsights($filteredIdsQueryBuilder),
    };
  }

  private function fetchOperationalInsightsRows(QueryBuilder $filteredIdsQueryBuilder): array
  {
    return $this->fetchReportSummaryRows(
      $this->createOperationalInsightsQueryBuilder($filteredIdsQueryBuilder, 'summary_order')
    );
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

  private function createOperationalInsightsQueryBuilder(
    QueryBuilder $filteredIdsQueryBuilder,
    string $alias
  ): QueryBuilder {
    $queryBuilder = $this->createQueryBuilder($alias);
    $queryBuilder
      ->join(sprintf('%s.orderProducts', $alias), 'summary_order_product')
      ->join('summary_order_product.product', 'summary_product')
      ->andWhere(sprintf('%s.id IN (%s)', $alias, $filteredIdsQueryBuilder->getDQL()))
      ->andWhere('summary_order_product.orderProduct IS NULL')
      ->select(
        sprintf('%s.id AS orderId', $alias),
        sprintf('%s.orderDate AS orderDate', $alias),
        sprintf('%s.app AS appName', $alias),
        'summary_product.id AS productId',
        'summary_product.product AS productName',
        'summary_order_product.quantity AS quantity'
      );

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

  private function buildOperationalInsightsTotals(array $rows): array
  {
    $uniqueOrders = [];
    $totalUnits = 0.0;

    foreach ($rows as $row) {
      $orderId = (int) ($row['orderId'] ?? 0);
      if ($orderId > 0) {
        $uniqueOrders[$orderId] = true;
      }

      $totalUnits += (float) ($row['quantity'] ?? 0);
    }

    return [
      'orders' => count($uniqueOrders),
      'units' => $totalUnits,
    ];
  }

  private function buildOperationalInsightsApps(array $rows): array
  {
    $groups = [];

    foreach ($rows as $row) {
      $appName = $this->normalizeReportText($row['appName'] ?? null);
      $appLabel = '' !== $appName ? $appName : 'POS';
      $groupKey = strtolower($appLabel);

      if (!isset($groups[$groupKey])) {
        $groups[$groupKey] = [
          'key' => $appLabel,
          'label' => $appLabel,
          'orders' => [],
          'units' => 0.0,
        ];
      }

      $groups[$groupKey]['orders'][(int) ($row['orderId'] ?? 0)] = true;
      $groups[$groupKey]['units'] += (float) ($row['quantity'] ?? 0);
    }

    $apps = array_values(array_map(static function (array $group): array {
      return [
        'key' => $group['key'],
        'label' => $group['label'],
        'orders' => count($group['orders']),
        'units' => $group['units'],
      ];
    }, $groups));

    usort($apps, static function (array $left, array $right): int {
      $unitsDifference = $right['units'] <=> $left['units'];

      if (0 !== $unitsDifference) {
        return $unitsDifference;
      }

      $ordersDifference = $right['orders'] <=> $left['orders'];

      if (0 !== $ordersDifference) {
        return $ordersDifference;
      }

      return strcmp($left['label'], $right['label']);
    });

    return $apps;
  }

  private function buildOperationalInsightsProducts(array $rows): array
  {
    $groups = [];

    foreach ($rows as $row) {
      $productId = (int) ($row['productId'] ?? 0);
      $productName = $this->normalizeReportText($row['productName'] ?? null);
      $groupKey = $productId > 0 ? (string) $productId : $productName;

      if (!isset($groups[$groupKey])) {
        $groups[$groupKey] = [
          'productId' => $productId,
          'key' => $productName,
          'label' => '' !== $productName ? $productName : 'Produto',
          'orders' => [],
          'units' => 0.0,
        ];
      }

      $groups[$groupKey]['orders'][(int) ($row['orderId'] ?? 0)] = true;
      $groups[$groupKey]['units'] += (float) ($row['quantity'] ?? 0);
    }

    $products = array_values(array_map(static function (array $group): array {
      return [
        'productId' => $group['productId'],
        'key' => $group['key'],
        'label' => $group['label'],
        'orders' => count($group['orders']),
        'units' => $group['units'],
      ];
    }, $groups));

    usort($products, static function (array $left, array $right): int {
      $unitsDifference = $right['units'] <=> $left['units'];

      if (0 !== $unitsDifference) {
        return $unitsDifference;
      }

      $ordersDifference = $right['orders'] <=> $left['orders'];

      if (0 !== $ordersDifference) {
        return $ordersDifference;
      }

      return strcmp($left['label'], $right['label']);
    });

    return $products;
  }

  private function buildOperationalInsightsDaily(array $rows): array
  {
    $groups = [];

    foreach ($rows as $row) {
      $dateKey = $this->normalizeReportDateKey($row['orderDate'] ?? null);
      if ('' === $dateKey) {
        continue;
      }

      if (!isset($groups[$dateKey])) {
        $groups[$dateKey] = [
          'date' => $dateKey,
          'label' => $this->formatReportDateLabel($dateKey),
          'orders' => [],
          'units' => 0.0,
        ];
      }

      $groups[$dateKey]['orders'][(int) ($row['orderId'] ?? 0)] = true;
      $groups[$dateKey]['units'] += (float) ($row['quantity'] ?? 0);
    }

    ksort($groups);

    return array_values(array_map(static function (array $group): array {
      return [
        'date' => $group['date'],
        'label' => $group['label'],
        'orders' => count($group['orders']),
        'units' => $group['units'],
      ];
    }, $groups));
  }

  private function buildOperationalInsightsAbc(array $products): array
  {
    $totalUnits = array_reduce(
      $products,
      static fn (float $carry, array $product): float => $carry + (float) ($product['units'] ?? 0),
      0.0
    );

    $bucketTotals = [
      'A' => ['items' => 0, 'units' => 0.0],
      'B' => ['items' => 0, 'units' => 0.0],
      'C' => ['items' => 0, 'units' => 0.0],
    ];
    $items = [];
    $cumulativeUnits = 0.0;

    foreach ($products as $product) {
      $units = (float) ($product['units'] ?? 0);
      $cumulativeUnits += $units;
      $cumulativeShare = $totalUnits > 0 ? ($cumulativeUnits / $totalUnits) * 100 : 0;
      $bucket = $cumulativeShare <= 80 ? 'A' : ($cumulativeShare <= 95 ? 'B' : 'C');

      $bucketTotals[$bucket]['items'] += 1;
      $bucketTotals[$bucket]['units'] += $units;

      $items[] = $product + [
        'share' => $totalUnits > 0 ? round(($units / $totalUnits) * 100, 2) : 0.0,
        'cumulativeShare' => round($cumulativeShare, 2),
        'bucket' => $bucket,
      ];
    }

    $buckets = [];

    foreach (['A', 'B', 'C'] as $bucket) {
      $buckets[] = [
        'bucket' => $bucket,
        'label' => $bucket,
        'items' => $bucketTotals[$bucket]['items'],
        'units' => $bucketTotals[$bucket]['units'],
        'share' => $totalUnits > 0 ? round(($bucketTotals[$bucket]['units'] / $totalUnits) * 100, 2) : 0.0,
      ];
    }

    return [
      'totalUnits' => $totalUnits,
      'items' => $items,
      'buckets' => $buckets,
    ];
  }

  private function normalizeReportDateKey(mixed $value): string
  {
    if ($value instanceof DateTimeInterface) {
      return $value->format('Y-m-d');
    }

    $normalized = $this->normalizeReportText($value);
    if ('' === $normalized) {
      return '';
    }

    try {
      return (new \DateTimeImmutable($normalized))->format('Y-m-d');
    } catch (\Throwable) {
      return '';
    }
  }

  private function formatReportDateLabel(string $dateKey): string
  {
    try {
      return (new \DateTimeImmutable($dateKey))->format('d/m');
    } catch (\Throwable) {
      return $dateKey;
    }
  }
}
