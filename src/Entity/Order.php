<?php

namespace ControleOnline\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use ApiPlatform\Core\Annotation\ApiSubresource;
use Symfony\Component\Serializer\Annotation\Groups;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ControleOnline\Entity\SalesOrderInvoice;
use stdClass;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiProperty;
use ControleOnline\Entity\OrderProduct;

/**
 * SalesOrder
 *
 * @ORM\EntityListeners({App\Listener\LogListener::class})
 * @ORM\Table(name="orders", uniqueConstraints={@ORM\UniqueConstraint(name="discount_id", columns={"discount_coupon_id"})}, indexes={@ORM\Index(name="adress_destination_id", columns={"address_destination_id"}), @ORM\Index(name="notified", columns={"notified"}), @ORM\Index(name="delivery_contact_id", columns={"delivery_contact_id"}), @ORM\Index(name="contract_id", columns={"contract_id"}), @ORM\Index(name="delivery_people_id", columns={"delivery_people_id"}), @ORM\Index(name="status_id", columns={"status_id"}), @ORM\Index(name="order_date", columns={"order_date"}), @ORM\Index(name="provider_id", columns={"provider_id"}), @ORM\Index(name="quote_id", columns={"quote_id", "provider_id"}), @ORM\Index(name="adress_origin_id", columns={"address_origin_id"}), @ORM\Index(name="retrieve_contact_id", columns={"retrieve_contact_id"}), @ORM\Index(name="main_order_id", columns={"main_order_id"}), @ORM\Index(name="retrieve_people_id", columns={"retrieve_people_id"}), @ORM\Index(name="payer_people_id", columns={"payer_people_id"}), @ORM\Index(name="client_id", columns={"client_id"}), @ORM\Index(name="alter_date", columns={"alter_date"}), @ORM\Index(name="IDX_E52FFDEEDB805178", columns={"quote_id"})})
 * @ORM\Entity(repositoryClass="ControleOnline\Repository\OrderRepository")
 */

#[ApiResource(
    operations: [
        new Get(
            security: 'is_granted(\'ROLE_CLIENT\')',
        ),
        new GetCollection(
            security: 'is_granted(\'ROLE_ADMIN\') or is_granted(\'ROLE_CLIENT\')',
        ),
        new Post(
            security: 'is_granted(\'ROLE_ADMIN\') or is_granted(\'ROLE_CLIENT\')',
            validationContext: ['groups' => ['order_write']],
            denormalizationContext: ['groups' => ['order_write']]
        ),
        new Put(
            security: 'is_granted(\'ROLE_ADMIN\') or (is_granted(\'ROLE_CLIENT\'))',
            validationContext: ['groups' => ['order_write']],
            denormalizationContext: ['groups' => ['order_write']]
        ),
    ],
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['order_read']],
    denormalizationContext: ['groups' => ['order_write']]
)]
#[ApiFilter(filterClass: OrderFilter::class, properties: ['alterDate' => 'DESC'])]


class Order
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @Groups({"order_read","company_expense_read","task_read","coupon_read","logistic_read","order_invoice_read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['id' => 'exact'])]

    private $id;

    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="client_id", referencedColumnName="id")
     * })
     * @Groups({"order_read","order_write", "invoice_read", "task_read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['client' => 'exact'])]

    private $client;

    /**
     * @var \DateTimeInterface
     * @ORM\Column(name="order_date", type="datetime",  nullable=false, columnDefinition="DATETIME")
     * @Groups({"order_read","order_write"})
     */
    #[ApiFilter(DateFilter::class, properties: ['orderDate'])]

    private $orderDate;

    /**
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\OrderProduct", mappedBy="order", cascade={"persist"})
     * @Groups({"order_read","order_write"})
     */
    private $orderProducts;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\SalesOrderInvoice", mappedBy="order")
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['invoice' => 'exact'])]

    private $invoice;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\Task", mappedBy="order")
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['task' => 'exact'])]

    private $task;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\SalesOrderInvoiceTax", mappedBy="order")
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['invoiceTax' => 'exact'])]

    private $invoiceTax;

    /**
     * @ORM\Column(name="alter_date", type="datetime",  nullable=false)
     * @Groups({"display_read","order_read","order_write"})
     */

    #[ApiFilter(DateFilter::class, properties: ['alterDate'])]

    private $alterDate;


    /**
     * @var \ControleOnline\Entity\Status
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Status")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="status_id", referencedColumnName="id")
     * })
     * @Groups({"display_read","order_read","order_write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['status' => 'exact'])]

    private $status;

    /**
     * @var string
     *
     * @ORM\Column(name="order_type", type="string",  nullable=true)
     * @Groups({"display_read","order_read","order_write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['orderType' => 'exact'])]

    private $orderType = 'Online';


    /**
     * @var string
     *
     * @ORM\Column(name="app", type="string",  nullable=true)
     * @Groups({"display_read","order_read","order_write"}) 
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['app' => 'exact'])]

    private $app = 'Manual';

    /**
     * @var string
     *
     * @ORM\Column(name="other_informations", type="json",  nullable=true)
     * @Groups({"order_read","order_write"}) 
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['otherInformations' => 'exact'])]

    private $otherInformations;

    /**
     * @var \ControleOnline\Entity\SalesOrder
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\SalesOrder")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="main_order_id", referencedColumnName="id")
     * })
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['mainOrder' => 'exact'])]

    private $mainOrder;


    /**
     * @var integer
     *
     * @ORM\Column(name="main_order_id", type="integer",  nullable=true)
     * @Groups({"order_read","order_write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['mainOrderId' => 'exact'])]

    private $mainOrderId;

    /**
     * @var \ControleOnline\Entity\Contract
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Contract")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="contract_id", referencedColumnName="id")
     * })
     * @Groups({"order_read","order_write","task_read","logistic_read"}) 
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['contract' => 'exact'])]

    private $contract;

    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="payer_people_id", referencedColumnName="id")
     * })
     * @Groups({"order_read","order_write","task_read","invoice_read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['payer' => 'exact'])]

    private $payer;

    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="provider_id", referencedColumnName="id")
     * })
     * @Groups({"order_read","order_write","invoice_read", "task_read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['provider' => 'exact'])]

    private $provider;

    /**
     * @var \ControleOnline\Entity\Quotation
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Quotation")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="quote_id", referencedColumnName="id")
     * })
     * @Groups({"order_read","order_write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['quote' => 'exact'])]

    private $quote;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\Quotation", mappedBy="order")
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['quotes' => 'exact'])]

    private $quotes;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\Retrieve", mappedBy="order")
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['retrieves' => 'exact'])]

    private $retrieves;

    /**
     * @var \ControleOnline\Entity\Address
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Address")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="address_origin_id", referencedColumnName="id")
     * })
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['addressOrigin' => 'exact'])]

    private $addressOrigin;

    /**
     * @var \ControleOnline\Entity\Address
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Address")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="address_destination_id", referencedColumnName="id")
     * })
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['addressDestination' => 'exact'])]

    private $addressDestination;

    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="retrieve_contact_id", referencedColumnName="id")
     * })
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['retrieveContact' => 'exact'])]

    private $retrieveContact;

    /**
     * @var \ControleOnline\Entity\People
     *
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\People")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="delivery_contact_id", referencedColumnName="id")
     * })
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['deliveryContact' => 'exact'])]

    private $deliveryContact;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\OrderPackage", mappedBy="order")
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['orderPackage' => 'exact'])]

    private $orderPackage;

    /**
     * @var float
     *
     * @ORM\Column(name="price", type="float",  nullable=false)
     * @Groups({"order_read","order_write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['price' => 'exact'])]

    private $price = 0;



    /**
     * @var string
     *
     * @ORM\Column(name="comments", type="string",  nullable=true)
     * @Groups({"order_read","order_write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['comments' => 'exact'])]

    private $comments;

    /**
     * @var boolean
     *
     * @ORM\Column(name="notified", type="boolean")
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['notified' => 'exact'])]

    private $notified = false;

    /**
     * @var \Doctrine\Common\Collections\Collection
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\OrderTracking", mappedBy="order")
     * @ApiSubresource()
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['tracking' => 'exact'])]

    private $tracking;



    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\OrderQueue", mappedBy="order")
     * @Groups({"order_read","order_write"}) 
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['orderQueue' => 'exact'])]

    private $orderQueue;



    public function __construct()
    {
        $this->orderDate    = new \DateTime('now');
        $this->alterDate    = new \DateTime('now');
        $this->orderPackage = new ArrayCollection();
        $this->invoiceTax   = new ArrayCollection();
        $this->invoice      = new ArrayCollection();
        $this->quotes       = new ArrayCollection();
        $this->retrieves    = new ArrayCollection();
        $this->tracking     = new ArrayCollection();
        $this->task         = new ArrayCollection();
        $this->orderQueue   = new ArrayCollection();
        $this->orderProducts = new ArrayCollection();
        // $this->parkingDate  = new \DateTime('now');
        $this->otherInformations = json_encode(new stdClass());
    }

    public function resetId()
    {
        $this->id          = null;
        $this->orderDate   = new \DateTime('now');
        $this->alterDate   = new \DateTime('now');
        // $this->parkingDate = new \DateTime('now');
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
     * Get otherInformations
     *
     * @return stdClass
     */
    public function getOtherInformations($decode = false)
    {
        return $decode ? (object) json_decode((is_array($this->otherInformations) ? json_encode($this->otherInformations) : $this->otherInformations)) : $this->otherInformations;
    }

    /**
     * Set comments
     *
     * @param string $otherInformations
     * @return Order
     */
    public function addOtherInformations($key, $value)
    {
        $otherInformations = $this->getOtherInformations(true);
        $otherInformations->$key = $value;
        $this->otherInformations = json_encode($otherInformations);
        return $this;
    }

    /**
     * Set comments
     *
     * @param string $otherInformations
     * @return Order
     */
    public function setOtherInformations($otherInformations)
    {
        $this->otherInformations = json_encode($otherInformations);
        return $this;
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
    public function setAlterDate(\DateTimeInterface $alter_date = null): self
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
     * @param \ControleOnline\Entity\SalesOrderInvoiceTax $invoice_tax
     * @return Order
     */
    public function addAInvoiceTax(SalesOrderInvoiceTax $invoice_tax)
    {
        $this->invoiceTax[] = $invoice_tax;

        return $this;
    }

    /**
     * Remove invoiceTax
     *
     * @param \ControleOnline\Entity\SalesOrderInvoiceTax $invoice_tax
     */
    public function removeInvoiceTax(SalesOrderInvoiceTax $invoice_tax)
    {
        $this->invoiceTax->removeElement($invoice_tax);
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
     * Get invoiceTax
     *
     * @return \ControleOnline\Entity\SalesInvoiceTax
     */
    public function getClientSalesInvoiceTax()
    {
        foreach ($this->getInvoiceTax() as $invoice) {
            if ($invoice->getInvoiceType() == 55) {
                return $invoice;
            }
        }
    }

    /**
     * Get invoiceTax
     *
     * @return \ControleOnline\Entity\SalesInvoiceTax
     */
    public function getClientInvoiceTax()
    {
        foreach ($this->getInvoiceTax() as $invoice) {
            if ($invoice->getInvoiceType() == 55) {
                return $invoice->getInvoiceTax();
            }
        }
    }
    /**
     * Get invoiceTax
     *
     * @return \ControleOnline\Entity\SalesInvoiceTax
     */
    public function getCarrierInvoiceTax()
    {
        foreach ($this->getInvoiceTax() as $invoice) {
            if ($invoice->getInvoiceType() == 57) {
                return $invoice->getInvoiceTax();
            }
        }
    }

    /**
     * Add SalesOrderInvoice
     *
     * @param \ControleOnline\Entity\SalesOrderInvoice $invoice
     * @return People
     */
    public function addInvoice(SalesOrderInvoice $invoice)
    {
        $this->invoice[] = $invoice;

        return $this;
    }

    /**
     * Remove SalesOrderInvoice
     *
     * @param \ControleOnline\Entity\SalesOrderInvoice $invoice
     */
    public function removeInvoice(SalesOrderInvoice $invoice)
    {
        $this->invoice->removeElement($invoice);
    }

    /**
     * Get SalesOrderInvoice
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getInvoice()
    {
        return $this->invoice;
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
     * Add retrieves
     *
     * @param \ControleOnline\Entity\Retrieve $retrieves
     * @return Order
     */
    public function addARetrieves(\ControleOnline\Entity\Retrieve $retrieves)
    {
        $this->retrieves[] = $retrieves;

        return $this;
    }

    /**
     * Remove retrieves
     *
     * @param \ControleOnline\Entity\Retrieve $retrieves
     */
    public function removeRetrieves(\ControleOnline\Entity\Retrieve $retrieves)
    {
        $this->retrieves->removeElement($retrieves);
    }

    /**
     * Get retrieves
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRetrieves()
    {
        return $this->retrieves;
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

    /**
     * Set app
     *
     * @param string $app
     * @return Order
     */
    public function setApp($app)
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Get app
     *
     * @return string
     */
    public function getApp()
    {
        return $this->app;
    }



    /**
     * Set mainOrder
     *
     * @param \ControleOnline\Entity\SalesOrder $mainOrder
     * @return Order
     */
    public function setMainOrder(\ControleOnline\Entity\SalesOrder $main_order = null)
    {
        $this->mainOrder = $main_order;

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
     * Set mainOrderId
     *
     * @param integer $mainOrderId
     * @return Order
     */
    public function setMainOrderId($mainOrderId)
    {
        $this->mainOrderId = $mainOrderId;

        return $this;
    }

    /**
     * Get mainOrderId
     *
     * @return integer
     */
    public function getMainOrderId()
    {
        return $this->mainOrderId;
    }

    /**
     * Set contract
     *
     * @param \ControleOnline\Entity\Contract $contract
     * @return SalesOrder
     */
    public function setContract($contract)
    {
        $this->contract = $contract;

        return $this;
    }

    public function getInvoiceByStatus(array $status)
    {
        foreach ($this->getInvoice() as $purchasingOrderInvoice) {
            $invoice = $purchasingOrderInvoice->getInvoice();
            if (in_array($invoice->getStatus()->getStatus(), $status)) {
                return $invoice;
            }
        }
    }
    /**
     * Get contract
     *
     * @return \ControleOnline\Entity\Contract
     */
    public function getContract()
    {
        return $this->contract;
    }

    public function canAccess($currentUser): bool
    {
        if (($provider = $this->getProvider()) === null)
            return false;

        return $currentUser->getPeople()->getLink()->exists(
            function ($key, $element) use ($provider) {
                return $element->getCompany() === $provider;
            }
        );
    }

    public function justOpened(): bool
    {
        return $this->getStatus()->getStatus() == 'quote';
    }

    /**
     * Get tracking
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getTracking()
    {
        return $this->tracking;
    }

    public function getOneInvoice()
    {
        return (($invoiceOrders = $this->getInvoice()->first()) === false) ?
            null : $invoiceOrders->getInvoice();
    }

    /**
     * Add Task
     *
     * @param \ControleOnline\Entity\Task $task
     * @return SalesOrder
     */
    public function addTask(\ControleOnline\Entity\Task $task)
    {
        $this->task[] = $task;

        return $this;
    }

    /**
     * Remove Task
     *
     * @param \ControleOnline\Entity\Task $task
     */
    public function removeTask(\ControleOnline\Entity\Task $task)
    {
        $this->task->removeElement($task);
    }

    /**
     * Get Task
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getTask()
    {
        return $this->task;
    }

    /**
     * Add OrderQueue
     *
     * @param \ControleOnline\Entity\OrderQueue $invoice_tax
     * @return Order
     */
    public function addAOrderQueue(\ControleOnline\Entity\OrderQueue $orderQueue)
    {
        $this->orderQueue[] = $orderQueue;

        return $this;
    }

    /**
     * Remove OrderQueue
     *
     * @param \ControleOnline\Entity\OrderQueue $invoice_tax
     */
    public function removeOrderQueue(\ControleOnline\Entity\OrderQueue $orderQueue)
    {
        $this->orderQueue->removeElement($orderQueue);
    }

    /**
     * Get OrderQueue
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getOrderQueue()
    {
        return $this->orderQueue;
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


    public function getOrderProducts()
    {
        return $this->orderProducts;
    }

    public function addOrderProduct(OrderProduct $orderProduct): self
    {
        $this->orderProducts[] = $orderProduct;
        return $this;
    }

    public function removeOrderProduct(OrderProduct $orderProduct): self
    {
        $this->orderProducts->removeElement($orderProduct);
        return $this;
    }
}
