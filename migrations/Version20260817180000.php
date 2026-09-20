<?php

declare(strict_types=1);

namespace DoctrineMigrations\Orders;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retired: the order commercial context columns are not part of the canonical model.';
    }

    public function up(Schema $schema): void
    {
        // Intentionally empty. Keep the published version available so tenants
        // can record it without creating columns that the application no longer uses.
    }

    public function down(Schema $schema): void
    {
        // Intentionally empty. Existing installations that already have the retired
        // columns keep them; schema cleanup requires a separately approved migration.
    }
}
