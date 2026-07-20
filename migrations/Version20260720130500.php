<?php

declare(strict_types=1);

namespace DoctrineMigrations\Orders;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260720130500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link order products to the resolved product showcase item.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order_product` ADD COLUMN `product_showcase_item_id` int(11) DEFAULT NULL AFTER `product_id`');
        $this->addSql('CREATE INDEX `product_showcase_item_id` ON `order_product` (`product_showcase_item_id`)');
        $this->addSql('ALTER TABLE `order_product` ADD CONSTRAINT `order_product_showcase_item_fk` FOREIGN KEY (`product_showcase_item_id`) REFERENCES `product_showcase_item` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function down(Schema $schema): void
    {
        return;
    }
}
