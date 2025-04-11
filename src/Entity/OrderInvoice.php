<?php

namespace ControleOnline\Entity; 
use ControleOnline\Listener\LogListener;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * OrderInvoice
 */
#[ApiResource(
    operations: [
        new Post(
            security: 'is_granted(\'ROLE_ADMIN\') or is_granted(\'ROLE_CLIENT\')',
            validationContext: ['groups' => ['order_invoice:write']],
            denormalizationContext: ['groups' => ['order_invoice:write']],

        ),
        new Get(security: 'is_granted(\'ROLE_CLIENT\')'),
        new GetCollection(security: 'is_granted(\'ROLE_CLIENT\')')
    ],
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['order_invoice:read']],
    denormalizationContext: ['groups' => ['order_invoice:write']]
)]
#[ORM\Table(name: 'order_invoice')]
#[ORM\Index(name: 'invoice_id', columns: ['invoice_id'])]
#[ORM\UniqueConstraint(name: 'order_id', columns: ['order_id', 'invoice_id'])]
#[ORM\EntityListeners([LogListener::class])]
#[ORM\Entity]

class OrderInvoice
{
    /**
     * @var integer
     *
     * @Groups({"order_invoice:read","order:read"})
     */
    #[ORM\Column(name: 'id', type: 'integer', nullable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;
    /**
     * @var \ControleOnline\Entity\Invoice
     *
     * @Groups({"order_invoice:read","order:read","order_details:read","order:write","order_invoice:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['invoice' => 'exact'])]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Invoice::class, inversedBy: 'order', cascade: ['persist'])]
    private $invoice;
    /**
     * @var \ControleOnline\Entity\Order
     *
     * @Groups({"invoice:read","invoice_details:read","order_invoice:read","order_invoice:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['order' => 'exact'])]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Order::class, inversedBy: 'invoice', cascade: ['persist'])]
    private $order;
    /**
     * @var float
     *
     * @Groups({"order_invoice:read","order:read","order_details:read","order:write","order_invoice:write"})
     *
     */
    #[ORM\Column(name: 'real_price', type: 'float', nullable: false)]
    private $realPrice = 0;
    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }
    /**
     * Set invoice
     *
     * @param \ControleOnline\Entity\Invoice $invoice
     * @return OrderInvoice
     */
    public function setInvoice(\ControleOnline\Entity\Invoice $invoice = null)
    {
        $this->invoice = $invoice;
        return $this;
    }
    /**
     * Get invoice
     *
     * @return \ControleOnline\Entity\Invoice
     */
    public function getInvoice()
    {
        return $this->invoice;
    }
    /**
     * Set order
     *
     * @param \ControleOnline\Entity\Order $order
     * @return OrderInvoice
     */
    public function setOrder(\ControleOnline\Entity\Order $order = null)
    {
        $this->order = $order;
        return $this;
    }
    /**
     * Get order
     *
     * @return \ControleOnline\Entity\Order
     */
    public function getOrder()
    {
        return $this->order;
    }
    /**
     * Set realPrice
     *
     * @param float $realPrice
     * @return OrderInvoice
     */
    public function setRealPrice($realPrice)
    {
        $this->realPrice = $realPrice;
        return $this;
    }
    /**
     * Get realPrice
     *
     * @return float
     */
    public function getRealPrice()
    {
        return $this->realPrice;
    }
}
