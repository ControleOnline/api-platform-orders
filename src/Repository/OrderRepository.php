<?php

namespace ControleOnline\Repository;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\Product;
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

  public function resolveSalesSummary(
    QueryBuilder $filteredIdsQueryBuilder,
    array $context = []
  ): array {
    $rows = $this->fetchSalesSummaryRows($filteredIdsQueryBuilder, $context);

    return [
      'totals' => $this->buildSalesSummaryTotals($rows),
      'daily' => $this->buildSalesSummarySeries($rows, 'day'),
      'weekly' => $this->buildSalesSummarySeries($rows, 'week'),
      'monthly' => $this->buildSalesSummarySeries($rows, 'month'),
    ];
  }

  public function resolveProductSalesSummary(
    Product $product,
    ?string $after = null,
    ?string $before = null
  ): array {
    $productId = (int) ($product->getId() ?? 0);
    $companyId = (int) ($product->getCompany()?->getId() ?? 0);

    if ($productId <= 0 || $companyId <= 0) {
      return $this->emptySalesSummary();
    }

    $filteredIdsQueryBuilder = $this->createQueryBuilder('filtered_order');
    $filteredIdsQueryBuilder
      ->select('filtered_order.id')
      ->join('filtered_order.orderProducts', 'filtered_order_product')
      ->leftJoin('filtered_order.status', 'filtered_status')
      ->andWhere('filtered_order.orderType = :salesOrderType')
      ->andWhere('filtered_status.realStatus = :salesRealStatus')
      ->andWhere('filtered_order_product.orderProduct IS NULL')
      ->andWhere('IDENTITY(filtered_order_product.product) = :salesProductId')
      ->andWhere('IDENTITY(filtered_order.provider) = :salesCompanyId')
      ->setParameter('salesOrderType', Order::ORDER_TYPE_SALE)
      ->setParameter('salesRealStatus', 'closed')
      ->setParameter('salesProductId', $productId)
      ->setParameter('salesCompanyId', $companyId)
      ->orderBy('filtered_order.orderDate', 'ASC')
      ->addOrderBy('filtered_order.id', 'ASC');

    if ($afterDate = $this->normalizeSalesBoundary($after)) {
      $filteredIdsQueryBuilder
        ->andWhere('filtered_order.orderDate >= :salesAfter')
        ->setParameter('salesAfter', $afterDate);
    }

    if ($beforeDate = $this->normalizeSalesBoundary($before, true)) {
      $filteredIdsQueryBuilder
        ->andWhere('filtered_order.orderDate <= :salesBefore')
        ->setParameter('salesBefore', $beforeDate);
    }

    return $this->resolveSalesSummary($filteredIdsQueryBuilder, [
      'filters' => [
        'product' => sprintf('/products/%d', $productId),
      ],
    ]);
  }

  public function findLatestMarketplaceOrderForProvider(int $providerId, string $app): ?Order
  {
    if ($providerId <= 0 || trim($app) === '') {
      return null;
    }

    return $this->createQueryBuilder('o')
      ->andWhere('IDENTITY(o.provider) = :providerId')
      ->andWhere('o.app = :app')
      ->setParameter('providerId', $providerId)
      ->setParameter('app', $app)
      ->orderBy('o.orderDate', 'DESC')
      ->addOrderBy('o.id', 'DESC')
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }

  /**
   * @param array<int, int|string> $productIds
   * @param array<int, int|string> $providerIds
   * @return array<int, array<string, mixed>>
   */
  public function findLatestPurchaseHistoryByProductIds(
    int $companyId,
    array $productIds,
    array $providerIds = []
  ): array {
    $normalizedProductIds = array_values(array_unique(array_filter(array_map(
      static fn ($value) => (int) $value,
      $productIds
    ))));

    if ($companyId <= 0 || $normalizedProductIds === []) {
      return [];
    }

    $normalizedProviderIds = array_values(array_unique(array_filter(array_map(
      static fn ($value) => (int) $value,
      $providerIds
    ))));

    $queryBuilder = $this->createQueryBuilder('o')
      ->join('o.orderProducts', 'op')
      ->leftJoin('o.provider', 'provider')
      ->select(
        'IDENTITY(op.product) AS productId',
        'o.id AS orderId',
        'o.orderDate AS orderDate',
        'o.alterDate AS alterDate',
        'op.quantity AS quantity',
        'op.price AS unitPrice',
        'op.total AS totalPrice',
        'provider.id AS providerId',
        'provider.name AS providerName',
        'provider.alias AS providerAlias'
      )
      ->andWhere('IDENTITY(o.client) = :companyId')
      ->andWhere('o.orderType = :purchaseType')
      ->andWhere('op.orderProduct IS NULL')
      ->andWhere('IDENTITY(op.product) IN (:productIds)')
      ->orderBy('o.orderDate', 'DESC')
      ->addOrderBy('o.id', 'DESC')
      ->setParameter('companyId', $companyId)
      ->setParameter('purchaseType', Order::ORDER_TYPE_PURCHASE)
      ->setParameter('productIds', $normalizedProductIds);

    if ($normalizedProviderIds !== []) {
      $queryBuilder
        ->andWhere('IDENTITY(o.provider) IN (:providerIds)')
        ->setParameter('providerIds', $normalizedProviderIds);
    }

    return $queryBuilder->getQuery()->getArrayResult();
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

  /**
   * @param array<string, mixed> $context
   * @return array<int, array<string, mixed>>
   */
  private function fetchSalesSummaryRows(
    QueryBuilder $filteredIdsQueryBuilder,
    array $context = []
  ): array {
    $summaryQueryBuilder = $this->createQueryBuilder('summary_order');
    $summaryQueryBuilder
      ->join('summary_order.orderProducts', 'summary_order_product')
      ->leftJoin('summary_order.status', 'summary_status')
      ->select(
        'summary_order.id AS orderId',
        'summary_order.orderDate AS orderDate',
        'summary_order_product.quantity AS quantity',
        'summary_order_product.total AS total'
      )
      ->andWhere(sprintf(
        'summary_order.id IN (%s)',
        $filteredIdsQueryBuilder->getDQL()
      ))
      ->andWhere('summary_order.orderType = :salesOrderType')
      ->andWhere('summary_status.realStatus = :salesRealStatus')
      ->andWhere('summary_order_product.orderProduct IS NULL')
      ->setParameter('salesOrderType', Order::ORDER_TYPE_SALE)
      ->setParameter('salesRealStatus', 'closed')
      ->orderBy('summary_order.orderDate', 'ASC')
      ->addOrderBy('summary_order.id', 'ASC');

    $salesProductId = $this->resolveSalesProductId($context);
    if ($salesProductId > 0) {
      $summaryQueryBuilder
        ->andWhere('IDENTITY(summary_order_product.product) = :salesProductId')
        ->setParameter('salesProductId', $salesProductId);
    }

    $this->copyReportParameters($summaryQueryBuilder, $filteredIdsQueryBuilder);

    $rows = $summaryQueryBuilder->getQuery()->getArrayResult();

    return array_values(array_filter(
      is_array($rows) ? $rows : [],
      static fn ($row) => is_array($row)
    ));
  }

  private function buildSalesSummaryTotals(array $rows): array
  {
    $uniqueOrders = [];
    $totalUnits = 0.0;
    $totalRevenue = 0.0;

    foreach ($rows as $row) {
      $orderId = (int) ($row['orderId'] ?? 0);
      if ($orderId > 0) {
        $uniqueOrders[$orderId] = true;
      }

      $totalUnits += (float) ($row['quantity'] ?? 0);
      $totalRevenue += (float) ($row['total'] ?? 0);
    }

    $orders = count($uniqueOrders);

    return [
      'orders' => $orders,
      'units' => $totalUnits,
      'revenue' => $totalRevenue,
      'averageTicket' => $orders > 0 ? ($totalRevenue / $orders) : 0.0,
    ];
  }

  private function buildSalesSummarySeries(array $rows, string $period): array
  {
    $groups = [];

    foreach ($rows as $row) {
      $date = $this->normalizeSalesDate($row['orderDate'] ?? null);
      if (!$date instanceof \DateTimeImmutable) {
        continue;
      }

      $bucket = $this->resolveSalesPeriodBucket($date, $period);
      $key = $bucket['key'] ?? null;
      $label = $bucket['label'] ?? null;

      if (!is_string($key) || '' === $key) {
        continue;
      }

      if (!isset($groups[$key])) {
        $groups[$key] = [
          'key' => $key,
          'label' => $label,
          'orders' => [],
          'units' => 0.0,
          'revenue' => 0.0,
        ];
      }

      $orderId = (int) ($row['orderId'] ?? 0);
      if ($orderId > 0) {
        $groups[$key]['orders'][$orderId] = true;
      }

      $groups[$key]['units'] += (float) ($row['quantity'] ?? 0);
      $groups[$key]['revenue'] += (float) ($row['total'] ?? 0);
    }

    ksort($groups);

    return array_values(array_map(static function (array $group): array {
      return [
        'key' => $group['key'],
        'label' => $group['label'],
        'orders' => count($group['orders']),
        'units' => (float) $group['units'],
        'revenue' => (float) $group['revenue'],
      ];
    }, $groups));
  }

  private function resolveSalesPeriodBucket(\DateTimeImmutable $date, string $period): array
  {
    return match (strtolower(trim($period))) {
      'week' => $this->resolveSalesWeekBucket($date),
      'month' => [
        'key' => $date->format('Y-m'),
        'label' => $date->format('m/Y'),
      ],
      default => [
        'key' => $date->format('Y-m-d'),
        'label' => $date->format('d/m'),
      ],
    };
  }

  private function resolveSalesWeekBucket(\DateTimeImmutable $date): array
  {
    $isoYear = (int) $date->format('o');
    $isoWeek = (int) $date->format('W');
    $weekStart = $date->setISODate($isoYear, $isoWeek, 1)->setTime(0, 0, 0);
    $weekEnd = $weekStart->modify('+6 days')->setTime(23, 59, 59);

    return [
      'key' => sprintf('%04d-W%02d', $isoYear, $isoWeek),
      'label' => sprintf(
        'Sem %02d · %s - %s',
        $isoWeek,
        $weekStart->format('d/m'),
        $weekEnd->format('d/m')
      ),
    ];
  }

  private function normalizeSalesDate(mixed $value): ?\DateTimeImmutable
  {
    if ($value instanceof \DateTimeImmutable) {
      return $value;
    }

    if ($value instanceof \DateTimeInterface) {
      return \DateTimeImmutable::createFromInterface($value);
    }

    $text = trim((string) $value);
    if ('' === $text) {
      return null;
    }

    try {
      return new \DateTimeImmutable($text);
    } catch (\Throwable) {
      return null;
    }
  }

  private function normalizeSalesBoundary(?string $value, bool $endOfDay = false): ?\DateTimeImmutable
  {
    $text = trim((string) $value);
    if ('' === $text) {
      return null;
    }

    try {
      $date = new \DateTimeImmutable($text);

      return $endOfDay
        ? $date->setTime(23, 59, 59)
        : $date->setTime(0, 0, 0);
    } catch (\Throwable) {
      return null;
    }
  }

  private function resolveSalesProductId(array $context = []): int
  {
    $filters = $context['filters'] ?? [];
    if (!is_array($filters)) {
      return 0;
    }

    foreach ([
      'product',
      'orderProducts.product',
      'orderProduct.product',
      'productId',
    ] as $filterName) {
      if (!array_key_exists($filterName, $filters)) {
        continue;
      }

      $productId = $this->extractNumericId($filters[$filterName]);
      if ($productId > 0) {
        return $productId;
      }
    }

    return 0;
  }

  private function emptySalesSummary(): array
  {
    return [
      'totals' => [
        'orders' => 0,
        'units' => 0.0,
        'revenue' => 0.0,
        'averageTicket' => 0.0,
      ],
      'daily' => [],
      'weekly' => [],
      'monthly' => [],
    ];
  }

  private function extractNumericId(mixed $value): int
  {
    if (is_array($value)) {
      foreach (['id', '@id', 'value'] as $key) {
        if (!array_key_exists($key, $value)) {
          continue;
        }

        $resolvedId = $this->extractNumericId($value[$key]);
        if ($resolvedId > 0) {
          return $resolvedId;
        }
      }

      return 0;
    }

    if (is_int($value)) {
      return $value > 0 ? $value : 0;
    }

    if (is_string($value)) {
      $digits = preg_replace('/\D+/', '', $value);
      return '' !== $digits ? (int) $digits : 0;
    }

    return 0;
  }
}
