<?php

namespace ControleOnline\Entity;

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
 *
 * @ORM\EntityListeners ({ControleOnline\Listener\LogListener::class})
 * @ORM\Table (name="order_invoice", uniqueConstraints={@ORM\UniqueConstraint (name="order_id", columns={"order_id", "invoice_id"})}, indexes={@ORM\Index (name="invoice_id", columns={"invoice_id"})})
 * @ORM\Entity
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

class OrderInvoice
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @Groups({"order_invoice:read","order:read"})
     */
    private $id;
    /**
     * @var \ControleOnline\Entity\Invoice
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Invoice", inversedBy="order", cascade={"persist"})
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="invoice_id", referencedColumnName="id")
     * })
     * @Groups({"order_invoice:read","order:read","order_invoice:write"}) 
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['invoice' => 'exact'])]
    private $invoice;
    /**
     * @var \ControleOnline\Entity\Order
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Order", inversedBy="invoice", cascade={"persist"})
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="order_id", referencedColumnName="id")
     * })
     * @Groups({"invoice:read","order_invoice:read","order_invoice:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['order' => 'exact'])]
    private $order;
    /**
     * @var float
     *
     * @ORM\Column(name="real_price", type="float",  nullable=false)
     * @Groups({"order_invoice:read","order:read","order_invoice:write"})
     * 
     */
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
