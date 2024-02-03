<?php

namespace ControleOnline\Entity;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use ControleOnline\Entity\Order;

/**
 * ComissionOrder
 *
 * @ORM\EntityListeners ({App\Listener\LogListener::class})
 * @ORM\Table (name="orders")
 * @ORM\Entity (repositoryClass="ControleOnline\Repository\ComissionOrderRepository")
 */
#[ApiResource(operations: [new Get(uriTemplate: '/comission/orders/{id}', security: 'is_granted(\'ROLE_CLIENT\')'), new GetCollection(security: 'is_granted(\'ROLE_CLIENT\')', uriTemplate: '/comission/orders')], formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']], normalizationContext: ['groups' => ['order_read']], denormalizationContext: ['groups' => ['order_write']])]
#[ApiFilter(filterClass: OrderFilter::class, properties: ['alterDate' => 'DESC'])]
#[ApiFilter(filterClass: SearchFilter::class, properties: ['invoice.invoice' => 'exact'])]
class ComissionOrder
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;
    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="client_id", referencedColumnName="id")
     * })
     * @Groups({"order_read", "invoice_read", "order_detail_status_read"})
     */
    private $client;
    /**
     * @var \DateTimeInterface
     * @ORM\Column(name="order_date", type="datetime",  nullable=false, columnDefinition="DATETIME")
     * @Groups({"order_read"})
     */
    private $orderDate;
    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\SalesOrder")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="main_order_id", referencedColumnName="id")
     * })
     * @Groups({"order_read", "invoice_read", "order_detail_status_read"})
     */
    private $mainOrder;
    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\ComissionOrderInvoice", mappedBy="order")
     */
    private $invoice;
    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\ComissionOrderInvoiceTax", mappedBy="order")
     * @Groups({"order_read", "order_detail_status_read"})
     */
    private $invoiceTax;
    /**
     * @ORM\Column(name="alter_date", type="datetime",  nullable=false)
     * @Groups({"order_read"})
     */
    private $alterDate;
    /**
     * @var \ControleOnline\Entity\Status
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Status")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="status_id", referencedColumnName="id")
     * })
     * @Groups({"order_read", "order_detail_status_read"})
     */
    private $status;
    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="delivery_people_id", referencedColumnName="id")
     * })
     * @Groups({"order_read"})
     */
    private $deliveryPeople;
    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="retrieve_people_id", referencedColumnName="id")
     * })
     * @Groups({"order_read"})
     */
    private $retrievePeople;
    /**
     * @var string
     *
     * @ORM\Column(name="order_type", type="string",  nullable=true)
     */
    private $orderType;
    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="payer_people_id", referencedColumnName="id")
     * })
     */
    private $payer;
    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="provider_id", referencedColumnName="id")
     * })
     * @Groups({"invoice_read"})
     */
    private $provider;
    /**
     * @var \ControleOnline\Entity\Quotation
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Quotation")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="quote_id", referencedColumnName="id")
     * })
     * @Groups({"order_read"})
     */
    private $quote;
    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\Quotation", mappedBy="order")
     */
    private $quotes;
    /**
     * @var \ControleOnline\Entity\Address
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Address")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="address_origin_id", referencedColumnName="id")
     * })
     */
    private $addressOrigin;
    /**
     * @var \ControleOnline\Entity\Address
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Address")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="address_destination_id", referencedColumnName="id")
     * })
     */
    private $addressDestination;
    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="retrieve_contact_id", referencedColumnName="id")
     * })
     */
    private $retrieveContact;
    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="delivery_contact_id", referencedColumnName="id")
     * })
     */
    private $deliveryContact;
    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\OrderPackage", mappedBy="order")
     */
    private $orderPackage;
    /**
     * @var float
     *
     * @ORM\Column(name="price", type="float",  nullable=false)
     * @Groups({"order_read", "order_detail_status_read"})
     */
    private $price;
    /**
     * @var float
     *
     * @ORM\Column(name="invoice_total", type="float",  nullable=false)
     * @Groups({"order_read"})
     */
    private $invoiceTotal = 0;
    /**
     * @var float
     *
     * @ORM\Column(name="cubage", type="float",  nullable=false)
     * @Groups({"order_read"})
     */
    private $cubage = 0;
    /**
     * @var string
     *
     * @ORM\Column(name="product_type", type="string",  nullable=false)
     * @Groups({"order_read"})
     */
    private $productType = '';
    /**
     * @var string
     *
     * @ORM\Column(name="comments", type="string",  nullable=true)
     * @Groups({"order_read"})
     */
    private $comments;
    /**
     * @var boolean
     *
     * @ORM\Column(name="notified", type="boolean")
     */
    private $notified = false;
    public function __construct()
    {
        $this->orderDate = new \DateTime('now');
        $this->alterDate = new \DateTime('now');
        $this->orderPackage = new ArrayCollection();
        $this->invoiceTax = new ArrayCollection();
        $this->invoice = new ArrayCollection();
        $this->quotes = new ArrayCollection();
        $this->orderType = 'sale';
    }
    public function resetId()
    {
        $this->id = null;
        $this->order_date = new \DateTime('now');
        $this->alter_date = new \DateTime('now');
    }
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
     * Set status
     *
     * @param \ControleOnline\Entity\Status $status
     * @return Order
     */
    public function setStatus(\ControleOnline\Entity\Status $status = null)
    {
        $this->status = $status;
        return $this;
    }
    /**
     * Get status
     *
     * @return \ControleOnline\Entity\Status
     */
    public function getStatus()
    {
        return $this->status;
    }
    /**
     * Set mainOrder
     *
     * @param \ControleOnline\Entity\SalesOrder $mainOrder
     * @return Order
     */
    public function setMainOrder(\ControleOnline\Entity\SalesOrder $mainOrder = null)
    {
        $this->mainOrder = $mainOrder;
        return $this;
    }
    /**
     * Get mainOrder
     *
     * @return \ControleOnline\Entity\SalesOrder
     */
    public function getMainOrder()
    {
        return $this->mainOrder;
    }
    /**
     * Set client
     *
     * @param \ControleOnline\Entity\People $client
     * @return Order
     */
    public function setClient(\ControleOnline\Entity\People $client = null)
    {
        $this->client = $client;
        return $this;
    }
    /**
     * Get client
     *
     * @return \ControleOnline\Entity\People
     */
    public function getClient()
    {
        return $this->client;
    }
    /**
     * Set provider
     *
     * @param \ControleOnline\Entity\People $provider
     * @return Order
     */
    public function setProvider(\ControleOnline\Entity\People $provider = null)
    {
        $this->provider = $provider;
        return $this;
    }
    /**
     * Get provider
     *
     * @return \ControleOnline\Entity\People
     */
    public function getProvider()
    {
        return $this->provider;
    }
    /**
     * Set price
     *
     * @param float $price
     * @return Order
     */
    public function setPrice($price)
    {
        $this->price = $price;
        return $this;
    }
    /**
     * Get price
     *
     * @return float
     */
    public function getPrice()
    {
        return $this->price;
    }
    /**
     * Set quote
     *
     * @param \ControleOnline\Entity\Quotation $quote
     * @return Order
     */
    public function setQuote(\ControleOnline\Entity\Quotation $quote = null)
    {
        $this->quote = $quote;
        return $this;
    }
    /**
     * Get quote
     *
     * @return \ControleOnline\Entity\Quotation
     */
    public function getQuote()
    {
        return $this->quote;
    }
    /**
     * Set addressOrigin
     *
     * @param \ControleOnline\Entity\Address $address_origin
     * @return Order
     */
    public function setAddressOrigin(\ControleOnline\Entity\Address $address_origin = null)
    {
        $this->addressOrigin = $address_origin;
        return $this;
    }
    /**
     * Get addressOrigin
     *
     * @return \ControleOnline\Entity\Address
     */
    public function getAddressOrigin()
    {
        return $this->addressOrigin;
    }
    /**
     * Set addressDestination
     *
     * @param \ControleOnline\Entity\Address $address_destination
     * @return Order
     */
    public function setAddressDestination(\ControleOnline\Entity\Address $address_destination = null)
    {
        $this->addressDestination = $address_destination;
        return $this;
    }
    /**
     * Get quote
     *
     * @return \ControleOnline\Entity\Address
     */
    public function getAddressDestination()
    {
        return $this->addressDestination;
    }
    /**
     * Get retrieveContact
     *
     * @return \ControleOnline\Entity\People
     */
    public function getRetrieveContact()
    {
        return $this->retrieveContact;
    }
    /**
     * Set retrieveContact
     *
     * @param \ControleOnline\Entity\People $retrieve_contact
     * @return Order
     */
    public function setRetrieveContact(\ControleOnline\Entity\People $retrieve_contact = null)
    {
        $this->retrieveContact = $retrieve_contact;
        return $this;
    }
    /**
     * Get deliveryContact
     *
     * @return \ControleOnline\Entity\People
     */
    public function getDeliveryContact()
    {
        return $this->deliveryContact;
    }
    /**
     * Set deliveryContact
     *
     * @param \ControleOnline\Entity\People $delivery_contact
     * @return Order
     */
    public function setDeliveryContact(\ControleOnline\Entity\People $delivery_contact = null)
    {
        $this->deliveryContact = $delivery_contact;
        return $this;
    }
    /**
     * Set payer
     *
     * @param \ControleOnline\Entity\People $payer
     * @return Order
     */
    public function setPayer(\ControleOnline\Entity\People $payer = null)
    {
        $this->payer = $payer;
        return $this;
    }
    /**
     * Get payer
     *
     * @return \ControleOnline\Entity\People
     */
    public function getPayer()
    {
        return $this->payer;
    }
    /**
     * Set deliveryPeople
     *
     * @param \ControleOnline\Entity\People $delivery_people
     * @return Order
     */
    public function setDeliveryPeople(\ControleOnline\Entity\People $delivery_people = null)
    {
        $this->deliveryPeople = $delivery_people;
        return $this;
    }
    /**
     * Get deliveryPeople
     *
     * @return \ControleOnline\Entity\People
     */
    public function getDeliveryPeople()
    {
        return $this->deliveryPeople;
    }
    /**
     * Set retrievePeople
     *
     * @param \ControleOnline\Entity\People $retrieve_people
     * @return Order
     */
    public function setRetrievePeople(\ControleOnline\Entity\People $retrieve_people = null): self
    {
        $this->retrievePeople = $retrieve_people;
        return $this;
    }
    /**
     * Get retrievePeople
     *
     * @return \ControleOnline\Entity\People
     */
    public function getRetrievePeople(): ?People
    {
        return $this->retrievePeople;
    }
    /**
     * Set comments
     *
     * @param string $comments
     * @return Order
     */
    public function setComments($comments)
    {
        $this->comments = $comments;
        return $this;
    }
    /**
     * Get comments
     *
     * @return string
     */
    public function getComments()
    {
        return $this->comments;
    }
    /**
     * Get orderDate
     *
     * @return \DateTimeInterface
     */
    public function getOrderDate()
    {
        return $this->orderDate;
    }
    /**
     * Set alter_date
     *
     * @param \DateTimeInterface $alter_date
     */
    public function setAlterDate(\DateTimeInterface $alter_date): self
    {
        $this->alterDate = $alter_date;
        return $this;
    }
    /**
     * Get alter_date
     *
     */
    public function getAlterDate(): ?\DateTimeInterface
    {
        return $this->alterDate;
    }
    /**
     * Add orderPackage
     *
     * @param \ControleOnline\Entity\OrderPackage $order_package
     * @return Order
     */
    public function addOrderPackage(\ControleOnline\Entity\OrderPackage $order_package)
    {
        $this->orderPackage[] = $order_package;
        return $this;
    }
    /**
     * Remove orderPackage
     *
     * @param \ControleOnline\Entity\OrderPackage $order_package
     */
    public function removeOrderPackage(\ControleOnline\Entity\OrderPackage $order_package)
    {
        $this->orderPackage->removeElement($order_package);
    }
    /**
     * Get orderPackage
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getOrderPackage()
    {
        return $this->orderPackage;
    }
    /**
     * Add invoiceTax
     *
     * @param \ControleOnline\Entity\ComissionOrderInvoiceTax $invoice_tax
     * @return Order
     */
    public function addAInvoiceTax(\ControleOnline\Entity\ComissionOrderInvoiceTax $invoice_tax)
    {
        $this->invoiceTax[] = $invoice_tax;
        return $this;
    }
    /**
     * Remove invoiceTax
     *
     * @param \ControleOnline\Entity\ComissionOrderInvoiceTax $invoice_tax
     */
    public function removeInvoiceTax(\ControleOnline\Entity\ComissionOrderInvoiceTax $invoice_tax)
    {
        $this->address->removeElement($invoice_tax);
    }
    /**
     * Get invoiceTax
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getInvoiceTax()
    {
        return $this->invoiceTax;
    }
    /**
     * Add ComissionOrderInvoice
     *
     * @param \ControleOnline\Entity\ComissionOrderInvoice $invoice
     * @return People
     */
    public function addInvoice(\ControleOnline\Entity\ComissionOrderInvoice $invoice)
    {
        $this->invoice[] = $invoice;
        return $this;
    }
    /**
     * Remove ComissionOrderInvoice
     *
     * @param \ControleOnline\Entity\ComissionOrderInvoice $invoice
     */
    public function removeInvoice(\ControleOnline\Entity\ComissionOrderInvoice $invoice)
    {
        $this->invoice->removeElement($invoice);
    }
    /**
     * Get ComissionOrderInvoice
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getInvoice()
    {
        return $this->invoice;
    }
    /**
     * Set invoiceTotal
     *
     * @param float $invoice_total
     * @return Order
     */
    public function setInvoiceTotal($invoice_total)
    {
        $this->invoiceTotal = $invoice_total;
        return $this;
    }
    /**
     * Get invoiceTotal
     *
     * @return float
     */
    public function getInvoiceTotal()
    {
        return $this->invoiceTotal;
    }
    /**
     * Set cubage
     *
     * @param float $cubage
     * @return Order
     */
    public function setCubage($cubage)
    {
        $this->cubage = $cubage;
        return $this;
    }
    /**
     * Get cubage
     *
     * @return float
     */
    public function getCubage()
    {
        return $this->cubage;
    }
    /**
     * Set product_type
     *
     * @param string $product_type
     * @return Order
     */
    public function setProductType($product_type)
    {
        $this->productType = $product_type;
        return $this;
    }
    /**
     * Get product_type
     *
     * @return string
     */
    public function getProductType()
    {
        return $this->productType;
    }
    /**
     * Add quotes
     *
     * @param \ControleOnline\Entity\Quotation $quotes
     * @return Order
     */
    public function addAQuotes(\ControleOnline\Entity\Quotation $quotes)
    {
        $this->quotes[] = $quotes;
        return $this;
    }
    /**
     * Remove quotes
     *
     * @param \ControleOnline\Entity\Quotation $quotes
     */
    public function removeQuotes(\ControleOnline\Entity\Quotation $quotes)
    {
        $this->quotes->removeElement($quotes);
    }
    /**
     * Get quotes
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getQuotes()
    {
        return $this->quotes;
    }
    /**
     * Get Notified
     *
     * @return boolean
     */
    public function getNotified()
    {
        return $this->notified;
    }
    /**
     * Set Notified
     *
     * @param boolean $notified
     * @return People
     */
    public function setNotified($notified)
    {
        $this->notified = $notified ? 1 : 0;
        return $this;
    }
    /**
     * Set orderType
     *
     * @param string $orderType
     * @return Order
     */
    public function setOrderType($order_type)
    {
        $this->orderType = $order_type;
        return $this;
    }
    /**
     * Get orderType
     *
     * @return string
     */
    public function getOrderType()
    {
        return $this->orderType;
    }
    public function canAccess(User $currentUser): bool
    {
        if (($provider = $this->getProvider()) === null) {
            return false;
        }
        return $currentUser->getPeople()->getPeopleCompany()->exists(function ($key, $element) use ($provider) {
            return $element->getCompany() === $provider;
        });
    }
    public function justOpened(): bool
    {
        return $this->getStatus()->getStatus() == 'quote';
    }


    public function isOriginAndDestinationTheSame(): ?bool
    {
        if (($origin = $this->getAddressOrigin()) === null) {
            return null;
        }

        if (($destination = $this->getAddressDestination()) === null) {
            return null;
        }

        $origCity = $origin->getStreet()->getDistrict()->getCity();
        $destCity = $destination->getStreet()->getDistrict()->getCity();

        // both objects are the same entity ( = same name and same state)

        if ($origCity === $destCity) {
            return true;
        }

        return false;
    }

    public function isOriginAndDestinationTheSameState(): ?bool
    {
        if (($origin = $this->getAddressOrigin()) === null) {
            return null;
        }

        if (($destination = $this->getAddressDestination()) === null) {
            return null;
        }

        $origState = $origin->getStreet()->getDistrict()->getCity()->getState();
        $destState = $destination->getStreet()->getDistrict()->getCity()->getState();

        // both objects are the same entity ( = same name and same country)

        if ($origState === $destState) {
            return true;
        }

        return false;
    }
}
