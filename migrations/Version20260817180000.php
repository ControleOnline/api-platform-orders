<?php

declare(strict_types=1);

namespace DoctrineMigrations\Orders;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist the canonical order channel, fulfillment intent, payment policy and applied operational snapshot.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `orders` ADD COLUMN `channel` varchar(16) DEFAULT NULL AFTER `app`, ADD COLUMN `fulfillment_type` varchar(16) DEFAULT NULL AFTER `channel`, ADD COLUMN `pay_before_production` tinyint(1) DEFAULT NULL AFTER `fulfillment_type`, ADD COLUMN `operational_snapshot` json DEFAULT NULL AFTER `pay_before_production`");
        $this->addSql("UPDATE `orders` SET `channel` = CASE LOWER(TRIM(COALESCE(`app`, ''))) WHEN 'pos' THEN 'pos' WHEN 'shop' THEN 'shop' ELSE 'external' END WHERE `channel` IS NULL");
        $this->addSql("UPDATE `orders` SET `fulfillment_type` = 'delivery' WHERE `fulfillment_type` IS NULL AND LOWER(TRIM(COALESCE(`order_type`, ''))) = 'delivery'");
        $this->addSql("UPDATE `orders` SET `operational_snapshot` = JSON_OBJECT('version', 1, 'channel', `channel`, 'app', `app`, 'fulfillmentType', `fulfillment_type`, 'payBeforeProduction', FALSE, 'linkType', CASE WHEN LOWER(TRIM(COALESCE(`order_type`, ''))) IN ('table', 'tab') THEN LOWER(TRIM(`order_type`)) ELSE 'none' END, 'legacyBackfill', TRUE) WHERE `operational_snapshot` IS NULL");
        $this->addSql('CREATE INDEX `order_channel` ON `orders` (`channel`)');
        $this->addSql('CREATE INDEX `order_fulfillment_type` ON `orders` (`fulfillment_type`)');
    }

    public function down(Schema $schema): void
    {
        // Reverse order of up(): drop indexes first, then columns.
        // Data written into the new columns is intentionally discarded on rollback
        // (columns are removed); that is the documented trade-off of this migration.
        $this->addSql('DROP INDEX `order_fulfillment_type` ON `orders`');
        $this->addSql('DROP INDEX `order_channel` ON `orders`');
        $this->addSql('ALTER TABLE `orders` DROP COLUMN `operational_snapshot`, DROP COLUMN `pay_before_production`, DROP COLUMN `fulfillment_type`, DROP COLUMN `channel`');
    }
}
