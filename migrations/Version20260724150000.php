<?php

declare(strict_types=1);

namespace DoctrineMigrations\Orders;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add order cancellation reason and user audit fields.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `orders` ADD COLUMN `cancellation_reason_id` int(11) DEFAULT NULL AFTER `other_informations`, ADD COLUMN `canceled_by_id` int(11) DEFAULT NULL AFTER `cancellation_reason_id`');
        $this->addSql('CREATE INDEX `cancellation_reason_id` ON `orders` (`cancellation_reason_id`)');
        $this->addSql('CREATE INDEX `canceled_by_id` ON `orders` (`canceled_by_id`)');
        $this->addSql('ALTER TABLE `orders` ADD CONSTRAINT `orders_cancellation_reason_fk` FOREIGN KEY (`cancellation_reason_id`) REFERENCES `category` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE `orders` ADD CONSTRAINT `orders_canceled_by_fk` FOREIGN KEY (`canceled_by_id`) REFERENCES `people` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function down(Schema $schema): void
    {
        return;
    }
}
