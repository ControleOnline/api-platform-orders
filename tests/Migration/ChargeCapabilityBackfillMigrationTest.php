<?php

namespace ControleOnline\Orders\Tests\Migration;

use PHPUnit\Framework\TestCase;

class ChargeCapabilityBackfillMigrationTest extends TestCase
{
    public function testKnownLegacyContextsReceiveExplicitCapabilityWithoutOverwritingDecision(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 2) . '/migrations/Version20260818120000.php',
        );

        self::assertIsString($migration);
        self::assertStringContainsString("IN ('PDV', 'MANAGER')", $migration);
        self::assertStringContainsString('order-charge-enabled', $migration);
        self::assertStringContainsString('JSON_CONTAINS_PATH', $migration);
        self::assertStringContainsString('JSON_VALID', $migration);
        self::assertStringNotContainsString('legacy-default', $migration);
    }
}
