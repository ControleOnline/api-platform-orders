<?php

namespace ControleOnline\Orders\Tests\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Version20260817180000 (commercial context columns).
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

    public function testUpAddsExpectedColumnsAndIndexes(): void
    {
        self::assertStringContainsString('ADD COLUMN `channel`', $this->migration);
        self::assertStringContainsString('ADD COLUMN `fulfillment_type`', $this->migration);
        self::assertStringContainsString('ADD COLUMN `pay_before_production`', $this->migration);
        self::assertStringContainsString('ADD COLUMN `operational_snapshot`', $this->migration);
        self::assertStringContainsString('CREATE INDEX `order_channel`', $this->migration);
        self::assertStringContainsString('CREATE INDEX `order_fulfillment_type`', $this->migration);
    }

    public function testDownIsFunctionalAndReversesInSafeOrder(): void
    {
        // Must not be an empty down()
        self::assertStringNotContainsString(
            "public function down(Schema \$schema): void\n    {\n        return;\n    }",
            $this->migration
        );

        $downPos = strpos($this->migration, 'function down');
        self::assertNotFalse($downPos);
        $downBody = substr($this->migration, $downPos);

        // Indexes dropped before columns
        $idxFulfillment = strpos($downBody, 'DROP INDEX `order_fulfillment_type`');
        $idxChannel = strpos($downBody, 'DROP INDEX `order_channel`');
        $dropCols = strpos($downBody, 'DROP COLUMN');
        self::assertNotFalse($idxFulfillment);
        self::assertNotFalse($idxChannel);
        self::assertNotFalse($dropCols);
        self::assertLessThan($dropCols, $idxFulfillment);
        self::assertLessThan($dropCols, $idxChannel);

        // All four columns removed
        self::assertStringContainsString('DROP COLUMN `operational_snapshot`', $downBody);
        self::assertStringContainsString('DROP COLUMN `pay_before_production`', $downBody);
        self::assertStringContainsString('DROP COLUMN `fulfillment_type`', $downBody);
        self::assertStringContainsString('DROP COLUMN `channel`', $downBody);
    }

    public function testDownDocumentsDataLossOnColumnRemoval(): void
    {
        $downPos = strpos($this->migration, 'function down');
        $downBody = substr($this->migration, $downPos);
        self::assertStringContainsString('intentionally discarded', $downBody);
    }
}
