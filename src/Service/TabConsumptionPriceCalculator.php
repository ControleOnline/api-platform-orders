<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use Doctrine\DBAL\{ArrayParameterType, Connection};

/** Prices a POS tab from confirmed sales, without changing orders or writing on reads. */
final class TabConsumptionPriceCalculator
{
    public function __construct(private readonly Connection $connection) {}

    public static function supports(Order $order): bool
    {
        return strtoupper(trim((string) $order->getApp())) === 'POS'
            && strtolower(trim((string) $order->getOrderType())) === 'tab';
    }

    public function calculate(Order $order): float
    {
        $rootId = (int) $order->getId();
        $providerId = (int) $order->getProvider()?->getId();
        if (!self::supports($order) || $rootId <= 0 || $providerId <= 0) {
            throw new \InvalidArgumentException('Identifique a comanda e sua empresa para calcular o consumo.');
        }

        $parents = [$rootId];
        $visited = [$rootId => true];
        $total = 0.0;
        while ($parents !== []) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT O.id, O.order_type, O.price, S.real_status
                 FROM orders O INNER JOIN status S ON S.id = O.status_id
                 WHERE O.main_order_id IN (:parent_ids)
                   AND O.provider_id = :provider_id AND O.app = :app',
                ['parent_ids' => $parents, 'provider_id' => $providerId, 'app' => $order->getApp()],
                ['parent_ids' => ArrayParameterType::INTEGER]
            );
            $parents = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if ($id <= 0 || isset($visited[$id])) continue;
                $visited[$id] = true;
                if (in_array(strtolower(trim((string) $row['real_status'])), ['canceled', 'cancelled'], true)) continue;
                $type = strtolower(trim((string) $row['order_type']));
                if ($type === 'sale') $total += (float) $row['price'];
                // Existing linked tabs are grouping nodes, never additional consumption.
                elseif ($type === 'tab') $parents[] = $id;
            }
        }
        return $total;
    }
}
