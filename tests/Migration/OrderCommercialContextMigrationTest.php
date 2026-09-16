<?php

namespace ControleOnline\Orders\Tests\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the retired Version20260817180000 migration.
 */
class OrderCommercialContextMigrationTest extends TestCase
{
    private string $migration;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/migrations/Version20260817180000.php';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $this->migration = $contents;
    }

    public function testMigrationRemainsAvailableButDoesNotMutateSchema(): void
    {
        self::assertStringContainsString('Retired:', $this->migration);
        self::assertStringNotContainsString('addSql(', $this->migration);
        self::assertStringContainsString('Intentionally empty', $this->migration);
    }
}
