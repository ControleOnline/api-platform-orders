<?php

declare(strict_types=1);

namespace DoctrineMigrations\Orders;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T2: idempotent fulfillment ledger per root OrderProduct (api-platform-orders#13).
 */
final class Version20260820060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create order_product_fulfillment ledger for unit fulfillment (T2).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `order_product_fulfillment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `order_product_id` int(11) NOT NULL,
  `action` varchar(32) NOT NULL,
  `quantity` double NOT NULL DEFAULT 1,
  `status` varchar(16) NOT NULL DEFAULT 'completed',
  `idempotency_key` varchar(64) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `device_origin` varchar(64) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `opf_idempotency_unique` (`idempotency_key`),
  KEY `opf_order_product_idx` (`order_product_id`),
  KEY `opf_order_idx` (`order_id`),
  KEY `opf_status_idx` (`status`),
  KEY `opf_actor_idx` (`actor_id`),
  CONSTRAINT `opf_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `opf_order_product_fk` FOREIGN KEY (`order_product_id`) REFERENCES `order_product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `opf_actor_fk` FOREIGN KEY (`actor_id`) REFERENCES `people` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS `order_product_fulfillment`');
    }
}
