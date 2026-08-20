<?php

declare(strict_types=1);

namespace DoctrineMigrations\Orders;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audited post-confirmation order product adjustments (api-platform-orders#14 / T3).
 */
final class Version20260820070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create order_product_adjustment audit table for post-confirmation quantity changes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `order_product_adjustment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `order_product_id` int(11) NOT NULL,
  `quantity_before` double NOT NULL,
  `quantity_after` double NOT NULL,
  `fulfilled_at_adjustment` double NOT NULL DEFAULT 0,
  `reason` varchar(255) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'committed',
  `idempotency_key` varchar(128) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `device_id` int(11) DEFAULT NULL,
  `snapshot` json DEFAULT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `opa_idempotency_key` (`idempotency_key`),
  KEY `opa_order_id` (`order_id`),
  KEY `opa_order_product_id` (`order_product_id`),
  KEY `opa_status` (`status`),
  KEY `opa_actor_id` (`actor_id`),
  CONSTRAINT `opa_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `opa_order_product_fk` FOREIGN KEY (`order_product_id`) REFERENCES `order_product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `opa_actor_fk` FOREIGN KEY (`actor_id`) REFERENCES `people` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order_product_adjustment` DROP FOREIGN KEY `opa_order_fk`');
        $this->addSql('ALTER TABLE `order_product_adjustment` DROP FOREIGN KEY `opa_order_product_fk`');
        $this->addSql('ALTER TABLE `order_product_adjustment` DROP FOREIGN KEY `opa_actor_fk`');
        $this->addSql('DROP TABLE IF EXISTS `order_product_adjustment`');
    }
}
