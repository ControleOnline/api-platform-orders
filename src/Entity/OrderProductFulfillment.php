<?php

namespace ControleOnline\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ControleOnline\Controller\OrderProductFulfillmentAction;
use ControleOnline\Repository\OrderProductFulfillmentRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Idempotent ledger of executed fulfillment for a root OrderProduct.
 * Production/queue state is independent; only explicit actions write here.
 */
#[ORM\Table(name: 'order_product_fulfillment')]
#[ORM\UniqueConstraint(name: 'opf_idempotency_unique', columns: ['idempotency_key'])]
#[ORM\Index(name: 'opf_order_product_idx', columns: ['order_product_id'])]
#[ORM\Index(name: 'opf_order_idx', columns: ['order_id'])]
#[ORM\Index(name: 'opf_status_idx', columns: ['status'])]
#[ORM\Entity(repositoryClass: OrderProductFulfillmentRepository::class)]
#[ApiResource(
    formats: ['jsonld', 'json', 'html', 'jsonhal'],
    normalizationContext: ['groups' => ['order_product_fulfillment:read']],
    denormalizationContext: ['groups' => ['order_product_fulfillment:write']],
    operations: [
        new GetCollection(security: "is_granted('ROLE_HUMAN')"),
        new Get(security: "is_granted('ROLE_HUMAN') or is_granted('ROLE_CLIENT')"),
        new Post(
            security: "is_granted('ROLE_HUMAN')",
            uriTemplate: '/order_product_fulfillments/execute',
            controller: OrderProductFulfillmentAction::class,
            read: false,
            deserialize: false,
            name: 'order_product_fulfillment_execute',
        ),
    ],
)]
#[ApiFilter(filterClass: SearchFilter::class, properties: [
    'order' => 'exact',
    'orderProduct' => 'exact',
    'action' => 'exact',
    'status' => 'exact',
    'idempotencyKey' => 'exact',
])]
#[ApiFilter(filterClass: DateFilter::class, properties: ['createdAt'])]
#[ApiFilter(filterClass: OrderFilter::class, properties: ['id', 'createdAt'])]
class OrderProductFulfillment
{
    public const ACTION_SERVED = 'served';
    public const ACTION_COUNTER_DELIVERED = 'counter_delivered';
    public const ACTION_PICKED_UP = 'picked_up';
    public const ACTION_DELIVERED = 'delivered';
    public const ACTION_DISPATCHED = 'dispatched';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALLOWED_ACTIONS = [
        self::ACTION_SERVED,
        self::ACTION_COUNTER_DELIVERED,
        self::ACTION_PICKED_UP,
        self::ACTION_DELIVERED,
        self::ACTION_DISPATCHED,
    ];

    #[ORM\Column(name: 'id', type: 'integer', nullable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[Groups(['order_product_fulfillment:read', 'order_details:read', 'order_conference:read'])]
    private $id;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['order_product_fulfillment:read', 'order_product_fulfillment:write'])]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: OrderProduct::class)]
    #[ORM\JoinColumn(name: 'order_product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['order_product_fulfillment:read', 'order_product_fulfillment:write', 'order_details:read', 'order_conference:read'])]
    private OrderProduct $orderProduct;

    #[ORM\Column(name: 'action', type: 'string', length: 32, nullable: false)]
    #[Groups(['order_product_fulfillment:read', 'order_product_fulfillment:write', 'order_details:read', 'order_conference:read'])]
    private string $action;

    #[ORM\Column(name: 'quantity', type: 'float', nullable: false, options: ['default' => 1])]
    #[Groups(['order_product_fulfillment:read', 'order_product_fulfillment:write', 'order_details:read', 'order_conference:read'])]
    private float $quantity = 1.0;

    #[ORM\Column(name: 'status', type: 'string', length: 16, nullable: false, options: ['default' => 'completed'])]
    #[Groups(['order_product_fulfillment:read', 'order_details:read', 'order_conference:read'])]
    private string $status = self::STATUS_COMPLETED;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 64, nullable: false)]
    #[Groups(['order_product_fulfillment:read', 'order_product_fulfillment:write'])]
    private string $idempotencyKey;

    #[ORM\ManyToOne(targetEntity: People::class)]
    #[ORM\JoinColumn(name: 'actor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['order_product_fulfillment:read'])]
    private ?People $actor = null;

    #[ORM\Column(name: 'device_origin', type: 'string', length: 64, nullable: true)]
    #[Groups(['order_product_fulfillment:read', 'order_product_fulfillment:write'])]
    private ?string $deviceOrigin = null;

    #[ORM\Column(name: 'reason', type: 'string', length: 255, nullable: true)]
    #[Groups(['order_product_fulfillment:read', 'order_product_fulfillment:write'])]
    private ?string $reason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime', nullable: false)]
    #[Groups(['order_product_fulfillment:read', 'order_details:read', 'order_conference:read'])]
    private DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTime('now');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function setOrder(Order $order): self
    {
        $this->order = $order;
        return $this;
    }

    public function getOrderProduct(): OrderProduct
    {
        return $this->orderProduct;
    }

    public function setOrderProduct(OrderProduct $orderProduct): self
    {
        $this->orderProduct = $orderProduct;
        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = strtolower(trim($action));
        return $this;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function setQuantity(float $quantity): self
    {
        $this->quantity = $quantity;
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

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(string $idempotencyKey): self
    {
        $this->idempotencyKey = trim($idempotencyKey);
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

    public function getDeviceOrigin(): ?string
    {
        return $this->deviceOrigin;
    }

    public function setDeviceOrigin(?string $deviceOrigin): self
    {
        $normalized = $deviceOrigin !== null ? trim($deviceOrigin) : null;
        $this->deviceOrigin = $normalized !== '' ? $normalized : null;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $normalized = $reason !== null ? trim($reason) : null;
        $this->reason = $normalized !== '' ? $normalized : null;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
