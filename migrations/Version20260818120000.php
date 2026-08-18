<?php

declare(strict_types=1);

namespace DoctrineMigrations\Orders;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill explicit charge capability for known legacy PDV and Manager device contexts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE device_configs
             SET configs = JSON_SET(
                 CASE WHEN JSON_VALID(configs) = 1 THEN configs ELSE JSON_OBJECT() END,
                 '$.\"order-charge-enabled\"', TRUE
             )
             WHERE UPPER(TRIM(device_type)) IN ('PDV', 'MANAGER')
               AND CASE
                   WHEN JSON_VALID(configs) = 1
                   THEN JSON_CONTAINS_PATH(configs, 'one', '$.\"order-charge-enabled\"')
                   ELSE 0
               END = 0"
        );
    }

    public function down(Schema $schema): void
    {
        return;
    }
}
