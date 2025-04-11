<?php

namespace ControleOnline\Entity; 
use ControleOnline\Listener\LogListener;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use ApiPlatform\Core\Annotation\ApiSubresource;
use Symfony\Component\Serializer\Annotation\Groups;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ControleOnline\Entity\OrderInvoice;
use stdClass;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\ApiResource;
use ControleOnline\Controller\CreateNFeAction;
use ControleOnline\Controller\DiscoveryCart;
use ControleOnline\Controller\PrintOrderAction;
use ControleOnline\Entity\OrderProduct;

/**
 * Order
 */
#[ApiResource(
    operations: [
        new Get(
            security: 'is_granted(\'ROLE_CLIENT\')',
        ),
        new GetCollection(
            security: 'is_granted(\'IS_AUTHENTICATED_ANONYMOUSLY\')',
            uriTemplate: '/cart',
            controller: DiscoveryCart::class
        ),
        new GetCollection(
            security: 'is_granted(\'ROLE_ADMIN\') or is_granted(\'ROLE_CLIENT\')',
            normalizationContext: ['groups' => ['order:read']],
        ),
        new Post(
            security: 'is_granted(\'ROLE_ADMIN\') or is_granted(\'ROLE_CLIENT\')',
            validationContext: ['groups' => ['order:write']],
            denormalizationContext: ['groups' => ['order:write']]
        ),
        new Put(
            security: 'is_granted(\'ROLE_ADMIN\') or (is_granted(\'ROLE_CLIENT\'))',
            validationContext: ['groups' => ['order:write']],
            denormalizationContext: ['groups' => ['order:write']]
        ),
        new Post(
            security: 'is_granted(\'ROLE_CLIENT\')',
            uriTemplate: '/orders/{id}/nfe',
            #requirements: ['format' => '^(pdf|xml)+$'],
            controller: CreateNFeAction::class
        ),
        new Post(
            security: 'is_granted(\'ROLE_CLIENT\')',
            uriTemplate: '/orders/{id}/print',
            controller: PrintOrderAction::class,
            denormalizationContext: ['groups' => ['print:write']],
            normalizationContext: ['groups' => ['print:read']],
        ),
    ],
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['order_details:read']],
    denormalizationContext: ['groups' => ['order:write']]
)]
#[ApiFilter(OrderFilter::class, properties: ['alterDate', 'id'])]
#[ORM\Table(name: 'orders')]
#[ORM\Index(name: 'adress_destination_id', columns: ['address_destination_id'])]
#[ORM\Index(name: 'notified', columns: ['notified'])]
#[ORM\Index(name: 'delivery_contact_id', columns: ['delivery_contact_id'])]
#[ORM\Index(name: 'delivery_people_id', columns: ['delivery_people_id'])]
#[ORM\Index(name: 'status_id', columns: ['status_id'])]
#[ORM\Index(name: 'order_date', columns: ['order_date'])]
#[ORM\Index(name: 'provider_id', columns: ['provider_id'])]
#[ORM\Index(name: 'quote_id', columns: ['quote_id', 'provider_id'])]
#[ORM\Index(name: 'adress_origin_id', columns: ['address_origin_id'])]
#[ORM\Index(name: 'retrieve_contact_id', columns: ['retrieve_contact_id'])]
#[ORM\Index(name: 'main_order_id', columns: ['main_order_id'])]
#[ORM\Index(name: 'retrieve_people_id', columns: ['retrieve_people_id'])]
#[ORM\Index(name: 'payer_people_id', columns: ['payer_people_id'])]
#[ORM\Index(name: 'client_id', columns: ['client_id'])]
#[ORM\Index(name: 'alter_date', columns: ['alter_date'])]
#[ORM\Index(name: 'IDX_E52FFDEEDB805178', columns: ['quote_id'])]
#[ORM\UniqueConstraint(name: 'discount_id', columns: ['discount_coupon_id'])]
#[ORM\EntityListeners([LogListener::class])]
#[ORM\Entity(repositoryClass: \ControleOnline\Repository\OrderRepository::class)]


class Order
{
    /**
     * @var integer
     *
     * @Groups({"order_product_queue:read","order:read","order_details:read","company_expense:read","coupon:read","logistic:read","order_invoice:read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['id' => 'exact'])]
    #[ORM\Column(name: 'id', type: 'integer', nullable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]

    private $id;

    /**
     * @var \ControleOnline\Entity\People
     *
     * @Groups({"order_product_queue:read","order_product_queue:read","order:read","order_details:read","order:write", "invoice:read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['client' => 'exact'])]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\People::class)]

    private $client;

    /**
     * @var \DateTimeInterface
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write"})
     */
    #[ApiFilter(DateFilter::class, properties: ['orderDate'])]
    #[ORM\Column(name: 'order_date', type: 'datetime', nullable: false, columnDefinition: 'DATETIME')]

    private $orderDate;

    /**
     * @Groups({"order_details:read","order:write"})
     */
    #[ORM\OneToMany(targetEntity: \ControleOnline\Entity\OrderProduct::class, mappedBy: 'order', cascade: ['persist'])]
    private $orderProducts;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['invoice' => 'exact'])]
    #[ORM\OneToMany(targetEntity: \ControleOnline\Entity\OrderInvoice::class, mappedBy: 'order')]
    private $invoice;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['task' => 'exact'])]
    #[ORM\OneToMany(targetEntity: \ControleOnline\Entity\Task::class, mappedBy: 'order')]

    private $task;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['invoiceTax' => 'exact'])]
    #[ORM\OneToMany(targetEntity: \ControleOnline\Entity\OrderInvoiceTax::class, mappedBy: 'order')]

    private $invoiceTax;

    /**
     * @Groups({"display:read","order_product_queue:read","order:read","order_details:read","order:write"})
     */
    #[ApiFilter(DateFilter::class, properties: ['alterDate'])]
    #[ORM\Column(name: 'alter_date', type: 'datetime', nullable: false)]

    private $alterDate;


    /**
     * @var \ControleOnline\Entity\Status
     *
     * @Groups({"order_product_queue:read","display:read","order_product_queue:read","order:read","order_details:read","order:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['status' => 'exact'])]
    #[ORM\JoinColumn(name: 'status_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Status::class)]

    private $status;

    /**
     * @var string
     *
     * @Groups({"order_product_queue:read","display:read","order_product_queue:read","order:read","order_details:read","order:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['orderType' => 'exact'])]
    #[ORM\Column(name: 'order_type', type: 'string', nullable: true)]

    private $orderType;


    /**
     * @var string
     *
     * @Groups({"order_product_queue:read","display:read","order_product_queue:read","order:read","order_details:read","order:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['app' => 'exact'])]
    #[ORM\Column(name: 'app', type: 'string', nullable: true)]

    private $app = 'Manual';

    /**
     * @var string
     *
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['otherInformations' => 'exact'])]
    #[ORM\Column(name: 'other_informations', type: 'json', nullable: true)]

    private $otherInformations;

    /**
     * @var \ControleOnline\Entity\Order
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['mainOrder' => 'exact'])]
    #[ORM\JoinColumn(name: 'main_order_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Order::class)]

    private $mainOrder;


    /**
     * @var integer
     *
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['mainOrderId' => 'exact'])]
    #[ORM\Column(name: 'main_order_id', type: 'integer', nullable: true)]

    private $mainOrderId;



    /**
     * @var \ControleOnline\Entity\People
     *
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","invoice:read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['payer' => 'exact'])]
    #[ORM\JoinColumn(name: 'payer_people_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\People::class)]

    private $payer;

    /**
     * @var \ControleOnline\Entity\People
     *
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","invoice:read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['provider' => 'exact'])]
    #[ORM\JoinColumn(name: 'provider_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\People::class)]

    private $provider;





    /**
     * @var \ControleOnline\Entity\Address
     *
     * @Groups({"order_details:read","order:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['addressOrigin' => 'exact'])]
    #[ORM\JoinColumn(name: 'address_origin_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Address::class)]

    private $addressOrigin;

    /**
     * @var \ControleOnline\Entity\Address
     *
     * @Groups({"order_details:read","order:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['addressDestination' => 'exact'])]
    #[ORM\JoinColumn(name: 'address_destination_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Address::class)]

    private $addressDestination;

    /**
     * @var \ControleOnline\Entity\People
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['retrieveContact' => 'exact'])]
    #[ORM\JoinColumn(name: 'retrieve_contact_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\People::class)]

    private $retrieveContact;

    /**
     * @var \ControleOnline\Entity\People
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['deliveryContact' => 'exact'])]
    #[ORM\JoinColumn(name: 'delivery_contact_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\People::class)]

    private $deliveryContact;


    /**
     * @var float
     *
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['price' => 'exact'])]
    #[ORM\Column(name: 'price', type: 'float', nullable: false)]

    private $price = 0;



    /**
     * @var string
     *
     * @Groups({"order_product_queue:read","order_product_queue:read","order:read","order_details:read","order:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['comments' => 'exact'])]
    #[ORM\Column(name: 'comments', type: 'string', nullable: true)]

    private $comments;

    /**
     * @var boolean
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['notified' => 'exact'])]
    #[ORM\Column(name: 'notified', type: 'boolean')]

    private $notified = false;

    /**
     * @Groups({"order_product_queue:read","display:read","order_product_queue:read","order:read","order_details:read","order:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['user' => 'exact'])]
    #[ORM\JoinColumn(nullable: true)]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\User::class)]
    private $user;

    /**
     * @var \ControleOnline\Entity\Device
     *
     * @Groups({"device_config:read","device:read","device_config:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['device' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['device.device' => 'exact'])]
    #[ORM\JoinColumn(name: 'device_id', referencedColumnName: 'id', nullable: true)]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Device::class)]
    private $device;



    public function __construct()
    {
        $this->orderDate    = new \DateTime('now');
        $this->alterDate    = new \DateTime('now');
        $this->invoiceTax   = new ArrayCollection();
        $this->invoice      = new ArrayCollection();
        $this->task         = new ArrayCollection();
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
     * Add invoiceTax
     *
     * @param \ControleOnline\Entity\OrderInvoiceTax $invoice_tax
     * @return Order
     */
    public function addAInvoiceTax(OrderInvoiceTax $invoice_tax)
    {
        $this->invoiceTax[] = $invoice_tax;

        return $this;
    }

    /**
     * Remove invoiceTax
     *
     * @param \ControleOnline\Entity\OrderInvoiceTax $invoice_tax
     */
    public function removeInvoiceTax(OrderInvoiceTax $invoice_tax)
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
     * @return \ControleOnline\Entity\InvoiceTax
     */
    public function getClientInvoiceTax()
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
     * @return \ControleOnline\Entity\InvoiceTax
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
     * Add OrderInvoice
     *
     * @param \ControleOnline\Entity\OrderInvoice $invoice
     * @return People
     */
    public function addInvoice(OrderInvoice $invoice)
    {
        $this->invoice[] = $invoice;

        return $this;
    }

    /**
     * Remove OrderInvoice
     *
     * @param \ControleOnline\Entity\OrderInvoice $invoice
     */
    public function removeInvoice(OrderInvoice $invoice)
    {
        $this->invoice->removeElement($invoice);
    }

    /**
     * Get OrderInvoice
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getInvoice()
    {
        return $this->invoice;
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
     * @param \ControleOnline\Entity\Order $mainOrder
     * @return Order
     */
    public function setMainOrder(\ControleOnline\Entity\Order $main_order = null)
    {
        $this->mainOrder = $main_order;

        return $this;
    }

    /**
     * Get mainOrder
     *
     * @return \ControleOnline\Entity\Order
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



    public function getInvoiceByStatus(array $status)
    {
        foreach ($this->getInvoice() as $purchasingOrderInvoice) {
            $invoice = $purchasingOrderInvoice->getInvoice();
            if (in_array($invoice->getStatus()->getStatus(), $status)) {
                return $invoice;
            }
        }
    }


    public function canAccess($currentUser): bool
    {
        if (($provider = $this->getProvider()) === null)
            return false;

        return $currentUser->getPeople()->getLink()->exists(
            fn($key, $element) => $element->getCompany() === $provider
        );
    }

    public function justOpened(): bool
    {
        return $this->getStatus()->getStatus() == 'quote';
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
     * @return Order
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

    /**
     * Get the value of user
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set the value of user
     */
    public function setUser($user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get the value of device
     */
    public function getDevice()
    {
        return $this->device;
    }

    /**
     * Set the value of device
     */
    public function setDevice($device): self
    {
        $this->device = $device;

        return $this;
    }
}
