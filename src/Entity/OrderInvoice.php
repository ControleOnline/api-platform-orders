<?php

namespace ControleOnline\Entity;

use Symfony\Component\Serializer\Attribute\Groups;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use Doctrine\ORM\Mapping as ORM;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Listener\LogListener;

#[ORM\Table(name: 'order_invoice')]
#[ORM\Index(name: 'invoice_id', columns: ['invoice_id'])]
#[ORM\UniqueConstraint(name: 'order_id', columns: ['order_id', 'invoice_id'])]
#[ORM\EntityListeners([LogListener::class])]
#[ORM\Entity]
#[ApiResource(
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['order_invoice:read']],
    denormalizationContext: ['groups' => ['order_invoice:write']],
    operations: [
        new GetCollection(security: "is_granted('ROLE_CLIENT')"),
        new Get(security: "is_granted('ROLE_CLIENT')"),
        new Post(
            security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')",
            validationContext: ['groups' => ['order_invoice:write']],
            denormalizationContext: ['groups' => ['order_invoice:write']]
        )
    ]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'invoice' => 'exact',
    'order' => 'exact'
])]
class OrderInvoice
{
    #[ORM\Column(name: 'id', type: 'integer', nullable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[Groups(['order_invoice:read', 'order:read'])]
    private $id;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'order', cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id')]
    #[Groups(['order_invoice:read', 'order:read', 'order_details:read', 'order:write',  'order_invoice:write'])]
    private $invoice;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'invoice', cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id')]
    #[Groups(['invoice:read', 'invoice_details:read', 'order_invoice:read', 'order_invoice:write'])]
    private $order;

    #[ORM\Column(name: 'real_price', type: 'float', nullable: false)]
    #[Groups(['order_invoice:read', 'order:read', 'order_details:read', 'order:write',  'order_invoice:write'])]
    private $realPrice = 0;

    public function getId()
    {
        return $this->id;
    }

    public function setInvoice(Invoice $invoice)
    {
        $this->invoice = $invoice;
        return $this;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function setOrder(Order $order)
    {
        $this->order = $order;
        return $this;
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function setRealPrice($realPrice)
    {
        $this->realPrice = $realPrice;
        return $this;
    }

    public function getRealPrice()
    {
        return $this->realPrice;
    }
}
