<?php

namespace ControleOnline\Orders\Tests\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Version20260818120000 (charge capability backfill).
 * Validates SQL shape and safety invariants without requiring a live DB.
 */
class ChargeCapabilityBackfillMigrationTest extends TestCase
{
    private string $migration;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/migrations/Version20260818120000.php';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $this->migration = $contents;
    }

    public function testTargetsOnlyKnownLegacyContexts(): void
    {
        self::assertStringContainsString("IN ('PDV', 'MANAGER')", $this->migration);
        self::assertStringContainsString('order-charge-enabled', $this->migration);
    }

    public function testNeverSubstitutesInvalidJsonWithEmptyObject(): void
    {
        // Forbidden pattern from the original migration: CASE WHEN JSON_VALID ... ELSE JSON_OBJECT()
        self::assertStringNotContainsString('ELSE JSON_OBJECT()', $this->migration);
        self::assertStringNotContainsString('ELSE {}', $this->migration);
        self::assertStringNotContainsString("ELSE '{}'", $this->migration);
    }

    public function testRequiresValidJsonBeforeTouchingRow(): void
    {
        self::assertStringContainsString('JSON_VALID', $this->migration);
        self::assertStringContainsString('JSON_CONTAINS_PATH', $this->migration);
        // Only rows missing the key are candidates
        self::assertMatchesRegularExpression(
            "/JSON_CONTAINS_PATH\([^)]+\)\s*=\s*0/",
            $this->migration
        );
    }

    public function testDoesNotOverwriteExistingOrderChargeEnabledDecision(): void
    {
        // Selection requires the path to be absent (= 0). Existing true/false are skipped.
        self::assertStringContainsString("JSON_CONTAINS_PATH", $this->migration);
        self::assertStringContainsString("= 0", $this->migration);
    }

    public function testTracksModifiedRowsForSafeRollback(): void
    {
        self::assertStringContainsString('_migration_version20260818120000_backfill', $this->migration);
        self::assertStringContainsString('previous_configs', $this->migration);
        self::assertStringContainsString('device_config_id', $this->migration);
    }

    public function testDownReportsConflictInsteadOfSilentOverwrite(): void
    {
        self::assertStringContainsString('Conflict on device_configs.id=', $this->migration);
        self::assertStringContainsString('leaving current value untouched', $this->migration);
        self::assertStringContainsString('manual recovery', $this->migration);
    }

    public function testDownRestoresOnlyWhenValueStillMatchesBackfill(): void
    {
        self::assertStringContainsString("order-charge-enabled", $this->migration);
        self::assertStringContainsString('=== true', $this->migration);
        self::assertStringContainsString('previous_configs', $this->migration);
    }

    public function testUpIsIdempotentByConstruction(): void
    {
        // Second run: rows already have the path → JSON_CONTAINS_PATH != 0 → not selected → no-op.
        self::assertStringContainsString('INSERT IGNORE', $this->migration);
        self::assertMatchesRegularExpression(
            "/JSON_CONTAINS_PATH\([^)]+\)\s*=\s*0/",
            $this->migration
        );
    }

    public function testNullAndInvalidConfigsAreSkipped(): void
    {
        self::assertStringContainsString('IS NOT NULL', $this->migration);
        self::assertStringContainsString('JSON_VALID(`configs`) = 1', $this->migration);
    }
}
