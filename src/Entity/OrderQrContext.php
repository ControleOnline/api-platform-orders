<?php

namespace ControleOnline\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ControleOnline\Controller\OrderQrConsumeAction;
use ControleOnline\Controller\OrderQrEmitAction;
use ControleOnline\Controller\OrderQrResolveAction;
use ControleOnline\Repository\OrderQrContextRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Opaque QR context for shop session/permanent tokens.
 * Internal order IDs are never exposed in the public token payload.
 */
#[ORM\Table(name: 'order_qr_context')]
#[ORM\UniqueConstraint(name: 'oqc_token_hash_unique', columns: ['token_hash'])]
#[ORM\Index(name: 'oqc_provider_idx', columns: ['provider_id'])]
#[ORM\Index(name: 'oqc_purpose_idx', columns: ['purpose'])]
#[ORM\Index(name: 'oqc_external_code_idx', columns: ['external_code'])]
#[ORM\Index(name: 'oqc_expires_idx', columns: ['expires_at'])]
#[ORM\Entity(repositoryClass: OrderQrContextRepository::class)]
#[ApiResource(
    formats: ['jsonld', 'json', 'html', 'jsonhal'],
    normalizationContext: ['groups' => ['order_qr_context:read']],
    denormalizationContext: ['groups' => ['order_qr_context:write']],
    operations: [
        new Get(
            security: "is_granted('ROLE_HUMAN')",
            normalizationContext: ['groups' => ['order_qr_context:read']],
        ),
        new Post(
            security: "is_granted('ROLE_HUMAN')",
            uriTemplate: '/order_qr_contexts/emit',
            controller: OrderQrEmitAction::class,
            read: false,
            deserialize: false,
            name: 'order_qr_context_emit',
        ),
        new Post(
            security: "is_granted('PUBLIC_ACCESS')",
            uriTemplate: '/order_qr_contexts/resolve',
            controller: OrderQrResolveAction::class,
            read: false,
            deserialize: false,
            name: 'order_qr_context_resolve',
        ),
        new Post(
            security: "is_granted('PUBLIC_ACCESS')",
            uriTemplate: '/order_qr_contexts/consume',
            controller: OrderQrConsumeAction::class,
            read: false,
            deserialize: false,
            name: 'order_qr_context_consume',
        ),
    ],
)]
class OrderQrContext
{
    public const PURPOSE_SESSION = 'session';
    public const PURPOSE_PERMANENT = 'permanent';
    public const PURPOSES = [self::PURPOSE_SESSION, self::PURPOSE_PERMANENT];

    public const LINK_NONE = 'none';
    public const LINK_TABLE = 'table';
    public const LINK_TAB = 'tab';
    public const LINK_TYPES = [self::LINK_NONE, self::LINK_TABLE, self::LINK_TAB];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['order_qr_context:read'])]
    private ?int $id = null;

    /**
     * SHA-256 hash of the opaque public token. The raw token is never stored.
     */
    #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
    private string $tokenHash = '';

    #[ORM\Column(name: 'purpose', type: 'string', length: 16)]
    #[Groups(['order_qr_context:read'])]
    private string $purpose = self::PURPOSE_SESSION;

    #[ORM\Column(name: 'link_type', type: 'string', length: 16)]
    #[Groups(['order_qr_context:read'])]
    private string $linkType = self::LINK_NONE;

    #[ORM\ManyToOne(targetEntity: People::class)]
    #[ORM\JoinColumn(name: 'provider_id', referencedColumnName: 'id', nullable: false)]
    #[Groups(['order_qr_context:read'])]
    private ?People $provider = null;

    /**
     * Logical table/tab/local external code. Resolved only server-side.
     */
    #[ORM\Column(name: 'external_code', type: 'string', length: 191, nullable: true)]
    #[Groups(['order_qr_context:read'])]
    private ?string $externalCode = null;

    /**
     * Session QR binds to a specific open root order. Permanent QR may leave this null.
     */
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'root_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Order $rootOrder = null;

    #[ORM\Column(name: 'status', type: 'string', length: 16)]
    #[Groups(['order_qr_context:read'])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'expires_at', type: 'datetime', nullable: true)]
    #[Groups(['order_qr_context:read'])]
    private ?DateTimeInterface $expiresAt = null;

    #[ORM\Column(name: 'revoked_at', type: 'datetime', nullable: true)]
    #[Groups(['order_qr_context:read'])]
    private ?DateTimeInterface $revokedAt = null;

    #[ORM\Column(name: 'key_version', type: 'integer')]
    #[Groups(['order_qr_context:read'])]
    private int $keyVersion = 1;

    #[ORM\ManyToOne(targetEntity: People::class)]
    #[ORM\JoinColumn(name: 'issued_by_id', referencedColumnName: 'id', nullable: true)]
    private ?People $issuedBy = null;

    #[ORM\Column(name: 'consume_count', type: 'integer')]
    private int $consumeCount = 0;

    #[ORM\Column(name: 'last_consumed_at', type: 'datetime', nullable: true)]
    private ?DateTimeInterface $lastConsumedAt = null;

    /**
     * Last idempotency key that successfully consumed this context (for audit).
     */
    #[ORM\Column(name: 'last_idempotency_key', type: 'string', length: 128, nullable: true)]
    private ?string $lastIdempotencyKey = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    #[Groups(['order_qr_context:read'])]
    private DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime')]
    private DateTimeInterface $updatedAt;

    public function __construct()
    {
        $now = new DateTime();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;
        return $this;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function setPurpose(string $purpose): self
    {
        $this->purpose = strtolower(trim($purpose));
        return $this;
    }

    public function getLinkType(): string
    {
        return $this->linkType;
    }

    public function setLinkType(string $linkType): self
    {
        $this->linkType = strtolower(trim($linkType));
        return $this;
    }

    public function getProvider(): ?People
    {
        return $this->provider;
    }

    public function setProvider(?People $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    public function getExternalCode(): ?string
    {
        return $this->externalCode;
    }

    public function setExternalCode(?string $externalCode): self
    {
        $normalized = $externalCode !== null ? trim($externalCode) : null;
        $this->externalCode = $normalized !== '' ? $normalized : null;
        return $this;
    }

    public function getRootOrder(): ?Order
    {
        return $this->rootOrder;
    }

    public function setRootOrder(?Order $rootOrder): self
    {
        $this->rootOrder = $rootOrder;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = strtolower(trim($status));
        return $this;
    }

    public function getExpiresAt(): ?DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?DateTimeInterface $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getRevokedAt(): ?DateTimeInterface
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?DateTimeInterface $revokedAt): self
    {
        $this->revokedAt = $revokedAt;
        return $this;
    }

    public function getKeyVersion(): int
    {
        return $this->keyVersion;
    }

    public function setKeyVersion(int $keyVersion): self
    {
        $this->keyVersion = $keyVersion;
        return $this;
    }

    public function getIssuedBy(): ?People
    {
        return $this->issuedBy;
    }

    public function setIssuedBy(?People $issuedBy): self
    {
        $this->issuedBy = $issuedBy;
        return $this;
    }

    public function getConsumeCount(): int
    {
        return $this->consumeCount;
    }

    public function incrementConsumeCount(): self
    {
        $this->consumeCount++;
        $this->lastConsumedAt = new DateTime();
        $this->touch();
        return $this;
    }

    public function getLastConsumedAt(): ?DateTimeInterface
    {
        return $this->lastConsumedAt;
    }

    public function getLastIdempotencyKey(): ?string
    {
        return $this->lastIdempotencyKey;
    }

    public function setLastIdempotencyKey(?string $lastIdempotencyKey): self
    {
        $this->lastIdempotencyKey = $lastIdempotencyKey;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new DateTime();
        return $this;
    }

    public function isExpired(?DateTimeInterface $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        $now = $now ?? new DateTime();
        return $this->expiresAt <= $now;
    }

    public function isUsable(?DateTimeInterface $now = null): bool
    {
        if ($this->status === self::STATUS_REVOKED) {
            return false;
        }
        if ($this->isExpired($now)) {
            return false;
        }
        return $this->status === self::STATUS_ACTIVE;
    }

    public function revoke(?DateTimeInterface $at = null): self
    {
        $this->status = self::STATUS_REVOKED;
        $this->revokedAt = $at ?? new DateTime();
        $this->touch();
        return $this;
    }
}
