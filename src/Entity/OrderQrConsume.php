<?php

namespace ControleOnline\Entity;

use ControleOnline\Repository\OrderQrConsumeRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Idempotent ledger of QR context consumptions.
 * Prevents duplicate carts/rounds for the same client idempotency key.
 */
#[ORM\Table(name: 'order_qr_consume')]
#[ORM\UniqueConstraint(name: 'oqr_consume_idempotency_unique', columns: ['idempotency_key'])]
#[ORM\Index(name: 'oqr_consume_context_idx', columns: ['qr_context_id'])]
#[ORM\Index(name: 'oqr_consume_order_idx', columns: ['order_id'])]
#[ORM\Entity(repositoryClass: OrderQrConsumeRepository::class)]
class OrderQrConsume
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrderQrContext::class)]
    #[ORM\JoinColumn(name: 'qr_context_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?OrderQrContext $qrContext = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Order $order = null;

    /**
     * Root order when link_type is table|tab; null for independent carts (none).
     */
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'root_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Order $rootOrder = null;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 128)]
    private string $idempotencyKey = '';

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQrContext(): ?OrderQrContext
    {
        return $this->qrContext;
    }

    public function setQrContext(?OrderQrContext $qrContext): self
    {
        $this->qrContext = $qrContext;
        return $this;
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

    public function getRootOrder(): ?Order
    {
        return $this->rootOrder;
    }

    public function setRootOrder(?Order $rootOrder): self
    {
        $this->rootOrder = $rootOrder;
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

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }
}
