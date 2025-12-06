<?php

namespace ControleOnline\Entity;

use Symfony\Component\Serializer\Attribute\Groups;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ControleOnline\Controller\AddProductsOrderAction;
use ControleOnline\Controller\CreateNFeAction;
use ControleOnline\Controller\DiscoveryCart;
use ControleOnline\Controller\PrintOrderAction;

use ControleOnline\Repository\OrderRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use stdClass;

#[ApiResource(
    operations: [
        new Get(
            security: 'is_granted(\'ROLE_CLIENT\')',
        ),
        new GetCollection(
            security: 'is_granted(\'PUBLIC_ACCESS\')',
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
            controller: CreateNFeAction::class
        ),
        new Post(
            security: 'is_granted(\'ROLE_CLIENT\')',
            uriTemplate: '/orders/{id}/print',
            controller: PrintOrderAction::class,
            denormalizationContext: ['groups' => ['print:write']],
            normalizationContext: ['groups' => ['print:read']],
        ),
        new Put(
            security: 'is_granted(\'ROLE_CLIENT\')',
            uriTemplate: '/orders/{id}/add-products',
            controller: AddProductsOrderAction::class,
            denormalizationContext: ['groups' => ['order:write']],
            normalizationContext: ['groups' => ['order_details:read']],
        ),

    ],
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['order_details:read']],
    denormalizationContext: ['groups' => ['order:write']],
    // AleMac // 06/12/2025 // ordenação padrão alterada para alterDate
    order: ['alterDate' => 'DESC', 'id', 'orderDate', 'provider', 'app', 'orderType', 'status', 'client']
)]

#[ApiFilter(OrderFilter::class, properties: [
    'alterDate',
    'id'
])]

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

#[ORM\Entity(repositoryClass: OrderRepository::class)]
class Order
{
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['id' => 'exact'])]
    #[ORM\Column(name: 'id', type: 'integer', nullable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write', 'company_expense:read', 'coupon:read', 'logistic:read', 'order_invoice:read'])]
    private $id;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['client' => 'exact'])]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: People::class)]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write',  'invoice:read'])]
    private $client;

    #[ApiFilter(DateFilter::class, properties: ['orderDate'])]
    #[ORM\Column(name: 'order_date', type: 'datetime', nullable: false, columnDefinition: 'DATETIME')]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $orderDate;

    #[ORM\OneToMany(targetEntity: OrderProduct::class, mappedBy: 'order', cascade: ['persist'])]
    #[Groups(['order_details:read', 'order:write', 'order:write'])]
    private $orderProducts;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['invoice' => 'exact'])]
    #[ORM\OneToMany(targetEntity: OrderInvoice::class, mappedBy: 'order')]
    private $invoice;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['task' => 'exact'])]
    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'order')]
    private $task;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['invoiceTax' => 'exact'])]
    #[ORM\OneToMany(targetEntity: OrderInvoiceTax::class, mappedBy: 'order')]
    private $invoiceTax;

    #[ApiFilter(DateFilter::class, properties: ['alterDate'])]
    #[ORM\Column(name: 'alter_date', type: 'datetime', nullable: false)]
    #[Groups(['display:read', 'order_product_queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $alterDate;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['status' => 'exact'])]
    #[ORM\JoinColumn(name: 'status_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: Status::class)]
    #[Groups(['order_product_queue:read', 'display:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $status;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['orderType' => 'exact'])]
    #[ORM\Column(name: 'order_type', type: 'string', nullable: true)]
    #[Groups(['order_product_queue:read', 'display:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $orderType;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['app' => 'exact'])]
    #[ORM\Column(name: 'app', type: 'string', nullable: true)]
    #[Groups(['order_product_queue:read', 'display:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $app = 'Manual';

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['otherInformations' => 'exact'])]
    #[ORM\Column(name: 'other_informations', type: 'json', nullable: true)]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $otherInformations;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['mainOrder' => 'exact'])]
    #[ORM\JoinColumn(name: 'main_order_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: self::class)]
    private $mainOrder;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['mainOrderId' => 'exact'])]
    #[ORM\Column(name: 'main_order_id', type: 'integer', nullable: true)]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $mainOrderId;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['payer' => 'exact'])]
    #[ORM\JoinColumn(name: 'payer_people_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: People::class)]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write',  'invoice:read'])]
    private $payer;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['provider' => 'exact'])]
    #[ORM\JoinColumn(name: 'provider_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: People::class)]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write',  'invoice:read'])]
    private $provider;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['addressOrigin' => 'exact'])]
    #[ORM\JoinColumn(name: 'address_origin_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: Address::class)]
    #[Groups(['order_details:read', 'order:write', 'order:write'])]
    private $addressOrigin;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['addressDestination' => 'exact'])]
    #[ORM\JoinColumn(name: 'address_destination_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: Address::class)]
    #[Groups(['order_details:read', 'order:write', 'order:write'])]
    private $addressDestination;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['retrieveContact' => 'exact'])]
    #[ORM\JoinColumn(name: 'retrieve_contact_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: People::class)]
    private $retrieveContact;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['deliveryContact' => 'exact'])]
    #[ORM\JoinColumn(name: 'delivery_contact_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: People::class)]
    private $deliveryContact;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['price' => 'exact'])]
    #[ORM\Column(name: 'price', type: 'float', nullable: false)]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $price = 0;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['comments' => 'exact'])]
    #[ORM\Column(name: 'comments', type: 'string', nullable: true)]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $comments;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['notified' => 'exact'])]
    #[ORM\Column(name: 'notified', type: 'boolean')]
    private $notified = false;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['user' => 'exact'])]
    #[ORM\JoinColumn(nullable: true)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[Groups(['order_product_queue:read', 'display:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $user;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['device' => 'exact', 'device.device' => 'exact'])]
    #[ORM\JoinColumn(name: 'device_id', referencedColumnName: 'id', nullable: true)]
    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[Groups(['device_config:read', 'device:read', 'device_config:write'])]
    private $device;

    public function __construct()
    {
        $this->orderDate = new DateTime('now');
        $this->alterDate = new DateTime('now');
        $this->invoiceTax = new ArrayCollection();
        $this->invoice = new ArrayCollection();
        $this->task = new ArrayCollection();
        $this->orderProducts = new ArrayCollection();
        $this->otherInformations = json_encode(new stdClass());
    }

    public function resetId()
    {
        $this->id = null;
        $this->orderDate = new DateTime('now');
        $this->alterDate = new DateTime('now');
    }

    public function getId()
    {
        return $this->id;
    }

    public function setStatus(Status $status = null)
    {
        $this->status = $status;
        return $this;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setClient(People $client = null)
    {
        $this->client = $client;
        return $this;
    }

    public function getClient()
    {
        return $this->client;
    }

    public function setProvider(People $provider = null)
    {
        $this->provider = $provider;
        return $this;
    }

    public function getProvider()
    {
        return $this->provider;
    }

    public function setPrice($price)
    {
        $this->price = $price;
        return $this;
    }

    public function getPrice()
    {
        return $this->price;
    }

    public function setAddressOrigin(Address $address_origin = null)
    {
        $this->addressOrigin = $address_origin;
        return $this;
    }

    public function getAddressOrigin()
    {
        return $this->addressOrigin;
    }

    public function setAddressDestination(Address $address_destination = null)
    {
        $this->addressDestination = $address_destination;
        return $this;
    }

    public function getAddressDestination()
    {
        return $this->addressDestination;
    }

    public function getRetrieveContact()
    {
        return $this->retrieveContact;
    }

    public function setRetrieveContact(People $retrieve_contact = null)
    {
        $this->retrieveContact = $retrieve_contact;
        return $this;
    }

    public function getDeliveryContact()
    {
        return $this->deliveryContact;
    }

    public function setDeliveryContact(People $delivery_contact = null)
    {
        $this->deliveryContact = $delivery_contact;
        return $this;
    }

    public function setPayer(People $payer = null)
    {
        $this->payer = $payer;
        return $this;
    }

    public function getPayer()
    {
        return $this->payer;
    }

    public function setComments($comments)
    {
        $this->comments = $comments;
        return $this;
    }

    public function getComments()
    {
        return $this->comments;
    }

    public function getOtherInformations($decode = false)
    {
        return $decode ? (object) json_decode((is_array($this->otherInformations) ? json_encode($this->otherInformations) : $this->otherInformations)) : $this->otherInformations;
    }

    public function addOtherInformations($key, $value)
    {
        $otherInformations = $this->getOtherInformations(true);
        $otherInformations->$key = $value;
        $this->otherInformations = json_encode($otherInformations);
        return $this;
    }

    public function setOtherInformations($otherInformations)
    {
        $this->otherInformations = json_encode($otherInformations);
        return $this;
    }

    public function getOrderDate()
    {
        return $this->orderDate;
    }

    public function setAlterDate(DateTimeInterface $alter_date = null): self
    {
        $this->alterDate = $alter_date;
        return $this;
    }

    public function getAlterDate(): ?DateTimeInterface
    {
        return $this->alterDate;
    }

    public function addAInvoiceTax(OrderInvoiceTax $invoice_tax)
    {
        $this->invoiceTax[] = $invoice_tax;
        return $this;
    }

    public function removeInvoiceTax(OrderInvoiceTax $invoice_tax)
    {
        $this->invoiceTax->removeElement($invoice_tax);
    }

    public function getInvoiceTax()
    {
        return $this->invoiceTax;
    }

    public function getClientInvoiceTax()
    {
        foreach ($this->getInvoiceTax() as $invoice) {
            if ($invoice->getInvoiceType() == 55) {
                return $invoice;
            }
        }
    }

    public function getCarrierInvoiceTax()
    {
        foreach ($this->getInvoiceTax() as $invoice) {
            if ($invoice->getInvoiceType() == 57) {
                return $invoice->getInvoiceTax();
            }
        }
    }

    public function addInvoice(OrderInvoice $invoice)
    {
        $this->invoice[] = $invoice;
        return $this;
    }

    public function removeInvoice(OrderInvoice $invoice)
    {
        $this->invoice->removeElement($invoice);
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function getNotified()
    {
        return $this->notified;
    }

    public function setNotified($notified)
    {
        $this->notified = $notified ? 1 : 0;
        return $this;
    }

    public function setOrderType($order_type)
    {
        $this->orderType = $order_type;
        return $this;
    }

    public function getOrderType()
    {
        return $this->orderType;
    }

    public function setApp($app)
    {
        $this->app = $app;
        return $this;
    }

    public function getApp()
    {
        return $this->app;
    }

    public function setMainOrder(self $main_order)
    {
        $this->mainOrder = $main_order;
        return $this;
    }

    public function getMainOrder()
    {
        return $this->mainOrder;
    }

    public function setMainOrderId($mainOrderId)
    {
        $this->mainOrderId = $mainOrderId;
        return $this;
    }

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
        if (($provider = $this->getProvider()) === null) {
            return false;
        }

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

    public function addTask(Task $task)
    {
        $this->task[] = $task;
        return $this;
    }

    public function removeTask(Task $task)
    {
        $this->task->removeElement($task);
    }

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

    public function getUser()
    {
        return $this->user;
    }

    public function setUser($user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getDevice()
    {
        return $this->device;
    }

    public function setDevice($device): self
    {
        $this->device = $device;
        return $this;
    }
}
