<?php

namespace ControleOnline\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ControleOnline\Controller\OrderProductAdjustmentAction;
use ControleOnline\Repository\OrderProductAdjustmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Audited post-confirmation adjustment of a root OrderProduct on a sale.
 * Free PUT/DELETE on sale remains blocked; only this explicit flow mutates.
 */
#[ORM\Table(name: 'order_product_adjustment')]
#[ORM\Index(name: 'opa_order_id', columns: ['order_id'])]
#[ORM\Index(name: 'opa_order_product_id', columns: ['order_product_id'])]
#[ORM\Index(name: 'opa_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'opa_idempotency_key', columns: ['idempotency_key'])]
#[ORM\Entity(repositoryClass: OrderProductAdjustmentRepository::class)]
#[ApiResource(
    formats: ['jsonld', 'json', 'html', 'jsonhal'],
    normalizationContext: ['groups' => ['order_product_adjustment:read']],
    denormalizationContext: ['groups' => ['order_product_adjustment:write']],
    operations: [
        new GetCollection(security: "is_granted('ROLE_HUMAN')"),
        new Get(security: "is_granted('ROLE_HUMAN')"),
        new Post(
            security: "is_granted('ROLE_HUMAN')",
            uriTemplate: '/order_product_adjustments/preview',
            controller: OrderProductAdjustmentAction::class,
            read: false,
            deserialize: false,
            name: 'order_product_adjustment_preview',
            defaults: ['_adjustment_mode' => 'preview'],
        ),
        new Post(
            security: "is_granted('ROLE_HUMAN')",
            uriTemplate: '/order_product_adjustments/commit',
            controller: OrderProductAdjustmentAction::class,
            read: false,
            deserialize: false,
            name: 'order_product_adjustment_commit',
            defaults: ['_adjustment_mode' => 'commit'],
        ),
    ],
)]
class OrderProductAdjustment
{
    public const STATUS_PREVIEW = 'preview';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Column(name: 'id', type: 'integer', nullable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[Groups(['order_product_adjustment:read'])]
    private $id;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['order_product_adjustment:read'])]
    private $order;

    #[ORM\ManyToOne(targetEntity: OrderProduct::class)]
    #[ORM\JoinColumn(name: 'order_product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['order_product_adjustment:read'])]
    private $orderProduct;

    #[ORM\Column(name: 'quantity_before', type: 'float', nullable: false)]
    #[Groups(['order_product_adjustment:read'])]
    private float $quantityBefore;

    #[ORM\Column(name: 'quantity_after', type: 'float', nullable: false)]
    #[Groups(['order_product_adjustment:read'])]
    private float $quantityAfter;

    #[ORM\Column(name: 'fulfilled_at_adjustment', type: 'float', nullable: false, options: ['default' => 0])]
    #[Groups(['order_product_adjustment:read'])]
    private float $fulfilledAtAdjustment = 0.0;

    #[ORM\Column(name: 'reason', type: 'string', length: 255, nullable: false)]
    #[Groups(['order_product_adjustment:read', 'order_product_adjustment:write'])]
    private string $reason;

    #[ORM\Column(name: 'status', type: 'string', length: 16, nullable: false)]
    #[Groups(['order_product_adjustment:read'])]
    private string $status = self::STATUS_COMMITTED;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 128, nullable: false)]
    #[Groups(['order_product_adjustment:read', 'order_product_adjustment:write'])]
    private string $idempotencyKey;

    #[ORM\ManyToOne(targetEntity: People::class)]
    #[ORM\JoinColumn(name: 'actor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['order_product_adjustment:read'])]
    private $actor;

    #[ORM\Column(name: 'device_id', type: 'integer', nullable: true)]
    #[Groups(['order_product_adjustment:read'])]
    private ?int $deviceId = null;

    #[ORM\Column(name: 'snapshot', type: 'json', nullable: true)]
    #[Groups(['order_product_adjustment:read'])]
    private ?array $snapshot = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: false)]
    #[Groups(['order_product_adjustment:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function getOrderProduct(): ?OrderProduct
    {
        return $this->orderProduct;
    }

    public function setOrderProduct(?OrderProduct $orderProduct): self
    {
        $this->orderProduct = $orderProduct;

        return $this;
    }

    public function getQuantityBefore(): float
    {
        return $this->quantityBefore;
    }

    public function setQuantityBefore(float $quantityBefore): self
    {
        $this->quantityBefore = $quantityBefore;

        return $this;
    }

    public function getQuantityAfter(): float
    {
        return $this->quantityAfter;
    }

    public function setQuantityAfter(float $quantityAfter): self
    {
        $this->quantityAfter = $quantityAfter;

        return $this;
    }

    public function getFulfilledAtAdjustment(): float
    {
        return $this->fulfilledAtAdjustment;
    }

    public function setFulfilledAtAdjustment(float $fulfilledAtAdjustment): self
    {
        $this->fulfilledAtAdjustment = $fulfilledAtAdjustment;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(string $idempotencyKey): self
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    public function getActor(): ?People
    {
        return $this->actor;
    }

    public function setActor(?People $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    public function getDeviceId(): ?int
    {
        return $this->deviceId;
    }

    public function setDeviceId(?int $deviceId): self
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    public function getSnapshot(): ?array
    {
        return $this->snapshot;
    }

    public function setSnapshot(?array $snapshot): self
    {
        $this->snapshot = $snapshot;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
