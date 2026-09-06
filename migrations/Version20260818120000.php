<?php

declare(strict_types=1);

namespace DoctrineMigrations\Orders;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill explicit charge capability for known legacy PDV/Manager contexts.
 *
 * Safety contract:
 * - Never replaces invalid/null JSON with {}.
 * - Preserves invalid configs byte-for-byte (those rows are skipped).
 * - Does not overwrite an existing order-charge-enabled (true or false).
 * - Idempotent: re-running up() is a no-op for already-backfilled rows.
 * - Tracks modified row ids so down() only touches records this migration changed.
 * - down() refuses to silently overwrite a concurrent/later change: if the current
 *   value is no longer the TRUE we set, the row is left untouched and reported.
 */
final class Version20260818120000 extends AbstractMigration
{
    private const TRACKING_TABLE = '_migration_version20260818120000_backfill';

    public function getDescription(): string
    {
        return 'Backfill explicit charge capability for known legacy PDV and Manager device contexts';
    }

    public function up(Schema $schema): void
    {
        // Tracking table of ids actually modified by this migration (for safe down()).
        $this->addSql(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `device_config_id` INT NOT NULL,
                `previous_configs` LONGTEXT NULL,
                PRIMARY KEY (`device_config_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            self::TRACKING_TABLE
        ));

        // Snapshot previous configs only for rows we will touch:
        // valid JSON, known legacy context, and missing order-charge-enabled.
        $this->addSql(sprintf(
            "INSERT IGNORE INTO `%s` (`device_config_id`, `previous_configs`)
             SELECT `id`, `configs`
             FROM `device_configs`
             WHERE UPPER(TRIM(`device_type`)) IN ('PDV', 'MANAGER')
               AND `configs` IS NOT NULL
               AND JSON_VALID(`configs`) = 1
               AND JSON_CONTAINS_PATH(`configs`, 'one', '$.\"order-charge-enabled\"') = 0",
            self::TRACKING_TABLE
        ));

        // Apply backfill only to the tracked rows (never invent {} for invalid JSON).
        $this->addSql(sprintf(
            "UPDATE `device_configs` dc
             INNER JOIN `%s` t ON t.`device_config_id` = dc.`id`
             SET dc.`configs` = JSON_SET(dc.`configs`, '$.\"order-charge-enabled\"', TRUE)",
            self::TRACKING_TABLE
        ));
    }

    public function down(Schema $schema): void
    {
        // Only reverse rows we actually changed. If the current value is still TRUE,
        // restore the previous configs snapshot. Otherwise report conflict and keep
        // the current value (no silent overwrite of concurrent/later changes).
        $conn = $this->connection;
        $rows = $conn->fetchAllAssociative(sprintf(
            'SELECT t.`device_config_id`, t.`previous_configs`, dc.`configs` AS `current_configs`
             FROM `%s` t
             INNER JOIN `device_configs` dc ON dc.`id` = t.`device_config_id`',
            self::TRACKING_TABLE
        ));

        foreach ($rows as $row) {
            $id = (int) $row['device_config_id'];
            $current = $row['current_configs'];
            $previous = $row['previous_configs'];

            $stillOurs = false;
            if (is_string($current) && $current !== '' && $this->isJson($current)) {
                $decoded = json_decode($current, true);
                $stillOurs = is_array($decoded)
                    && array_key_exists('order-charge-enabled', $decoded)
                    && $decoded['order-charge-enabled'] === true;
            }

            if ($stillOurs) {
                $conn->executeStatement(
                    'UPDATE `device_configs` SET `configs` = ? WHERE `id` = ?',
                    [$previous, $id]
                );
            } else {
                $this->write(sprintf(
                    'Conflict on device_configs.id=%d: current configs no longer match the TRUE value set by this migration; leaving current value untouched for manual recovery.',
                    $id
                ));
            }
        }

        $this->addSql(sprintf('DROP TABLE IF EXISTS `%s`', self::TRACKING_TABLE));
    }

    private function isJson(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
