<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T4: order_qr_context + order_qr_consume for secure Shop QR tokens.
 */
final class Version20260820080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create order_qr_context and order_qr_consume tables (secure QR tokens)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE order_qr_context (
            id INT AUTO_INCREMENT NOT NULL,
            provider_id INT NOT NULL,
            root_order_id INT DEFAULT NULL,
            issued_by_id INT DEFAULT NULL,
            token_hash VARCHAR(64) NOT NULL,
            purpose VARCHAR(16) NOT NULL,
            link_type VARCHAR(16) NOT NULL,
            external_code VARCHAR(191) DEFAULT NULL,
            status VARCHAR(16) NOT NULL,
            expires_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            key_version INT NOT NULL,
            consume_count INT NOT NULL,
            last_consumed_at DATETIME DEFAULT NULL,
            last_idempotency_key VARCHAR(128) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX oqc_token_hash_unique (token_hash),
            INDEX oqc_provider_idx (provider_id),
            INDEX oqc_purpose_idx (purpose),
            INDEX oqc_external_code_idx (external_code),
            INDEX oqc_expires_idx (expires_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE order_qr_consume (
            id INT AUTO_INCREMENT NOT NULL,
            qr_context_id INT NOT NULL,
            order_id INT NOT NULL,
            root_order_id INT DEFAULT NULL,
            idempotency_key VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX oqr_consume_idempotency_unique (idempotency_key),
            INDEX oqr_consume_context_idx (qr_context_id),
            INDEX oqr_consume_order_idx (order_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // FKs are best-effort: people/orders tables live in shared schema
        $this->addSql('ALTER TABLE order_qr_context ADD CONSTRAINT FK_OQC_PROVIDER FOREIGN KEY (provider_id) REFERENCES people (id)');
        $this->addSql('ALTER TABLE order_qr_context ADD CONSTRAINT FK_OQC_ROOT_ORDER FOREIGN KEY (root_order_id) REFERENCES orders (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE order_qr_context ADD CONSTRAINT FK_OQC_ISSUED_BY FOREIGN KEY (issued_by_id) REFERENCES people (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE order_qr_consume ADD CONSTRAINT FK_OQR_CONTEXT FOREIGN KEY (qr_context_id) REFERENCES order_qr_context (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE order_qr_consume ADD CONSTRAINT FK_OQR_ORDER FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE order_qr_consume ADD CONSTRAINT FK_OQR_ROOT_ORDER FOREIGN KEY (root_order_id) REFERENCES orders (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_qr_consume DROP FOREIGN KEY FK_OQR_CONTEXT');
        $this->addSql('ALTER TABLE order_qr_consume DROP FOREIGN KEY FK_OQR_ORDER');
        $this->addSql('ALTER TABLE order_qr_consume DROP FOREIGN KEY FK_OQR_ROOT_ORDER');
        $this->addSql('ALTER TABLE order_qr_context DROP FOREIGN KEY FK_OQC_PROVIDER');
        $this->addSql('ALTER TABLE order_qr_context DROP FOREIGN KEY FK_OQC_ROOT_ORDER');
        $this->addSql('ALTER TABLE order_qr_context DROP FOREIGN KEY FK_OQC_ISSUED_BY');
        $this->addSql('DROP TABLE order_qr_consume');
        $this->addSql('DROP TABLE order_qr_context');
    }
}
