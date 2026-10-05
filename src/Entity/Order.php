<?php

namespace ControleOnline\Entity;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

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
use ControleOnline\Controller\AnonymousCartController;
use ControleOnline\Controller\AutoConferencePrintOrderAction;
use ControleOnline\Controller\CreateNFeAction;
use ControleOnline\Controller\DiscoveryCart;
use ControleOnline\Controller\FidelityByIdController;
use ControleOnline\Controller\OrderConferenceController;
use ControleOnline\Controller\PrintOrderAction;
use ControleOnline\Controller\ReplaceProductsOrderAction;
use ControleOnline\Controller\UpdateOrderAction;
use ControleOnline\Attribute\CollectionSummary;
use ControleOnline\Filter\CustomOrFilter;

use ControleOnline\Repository\OrderRepository;
use ControleOnline\State\HydratedReadProvider;
use ControleOnline\Service\OrderReportSummaryResolver;
use ControleOnline\Service\OrderSalesSummaryResolver;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use stdClass;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
    new Get(
            security: 'is_granted(\'ROLE_HUMAN\') or is_granted(\'ROLE_CLIENT\')',
            provider: HydratedReadProvider::class,
            normalizationContext: ['groups' => ['order_details:read']],
        ),
        new GetCollection(
            security: 'is_granted(\'ROLE_HUMAN\') or is_granted(\'ROLE_CLIENT\')',
            uriTemplate: '/cart',
            controller: DiscoveryCart::class
        ),
        new GetCollection(
            security: 'is_granted(\'PUBLIC_ACCESS\')',
            uriTemplate: '/anonymous-cart',
            controller: AnonymousCartController::class,
            read: false,
            paginationEnabled: false,
            normalizationContext: ['groups' => ['order_details:read']],
        ),
        new Post(
            security: 'is_granted(\'PUBLIC_ACCESS\')',
            uriTemplate: '/anonymous-cart/items',
            controller: AnonymousCartController::class,
            read: false,
            deserialize: false,
            normalizationContext: ['groups' => ['order_details:read']],
        ),
        new GetCollection(
            security: 'is_granted(\'ROLE_HUMAN\') or is_granted(\'ROLE_CLIENT\')',
            provider: HydratedReadProvider::class,
            normalizationContext: ['groups' => ['order:read']],
        ),
        new GetCollection(
            uriTemplate: '/orders-tracking',
            security: 'is_granted(\'ROLE_HUMAN\')',
            provider: HydratedReadProvider::class,
            forceEager: false,
            normalizationContext: [
                'groups' => ['tracking:read'],
                'enable_max_depth' => true,
            ],
        ),
        new Get(
            security: 'is_granted(\'ROLE_HUMAN\')',
            uriTemplate: '/orders/{id}/conference',
            controller: OrderConferenceController::class,
            read: false,
            normalizationContext: ['groups' => ['order:read', 'order_conference:read']],
        ),
        new GetCollection(
            uriTemplate: '/orders-queue',
            security: 'is_granted(\'ROLE_HUMAN\')',
            normalizationContext: ['groups' => ['orders-queue:read', 'orders-queue-tree:read']],
        ),
        new GetCollection(
            uriTemplate: '/orders/fidelityById/{id}',
            security: 'is_granted(\'ROLE_HUMAN\') or is_granted(\'ROLE_CLIENT\')',
            controller: FidelityByIdController::class,
            read: false,
            paginationEnabled: false,
        ),
        new Post(
            security: 'is_granted(\'ROLE_HUMAN\')',
            validationContext: ['groups' => ['order:write']],
            normalizationContext: ['groups' => ['order:read', 'order_creation:read']],
            denormalizationContext: ['groups' => ['order:write']]
        ),
        new Put(
            security: 'is_granted(\'ROLE_HUMAN\')',
            controller: UpdateOrderAction::class,
            read: false,
            deserialize: false
        ),
        new Post(
            security: 'is_granted(\'ROLE_HUMAN\')',
            uriTemplate: '/orders/{id}/nfe',
            controller: CreateNFeAction::class
        ),
        new Post(
            security: 'is_granted(\'ROLE_HUMAN\')',
            uriTemplate: '/orders/{id}/print',
            controller: PrintOrderAction::class,
            denormalizationContext: ['groups' => ['print:write']],
            normalizationContext: ['groups' => ['print:read']],
        ),
        new Post(
            security: 'is_granted(\'ROLE_HUMAN\')',
            uriTemplate: '/orders/{id}/conference-print',
            controller: AutoConferencePrintOrderAction::class,
            denormalizationContext: ['groups' => ['print:write']],
            normalizationContext: ['groups' => ['order:read']],
        ),
        new Put(
            security: 'is_granted(\'ROLE_HUMAN\')',
            uriTemplate: '/orders/{id}/add-products',
            controller: AddProductsOrderAction::class,
            denormalizationContext: ['groups' => ['order:write']],
            normalizationContext: ['groups' => ['order_details:read']],
        ),
        new Put(
            security: 'is_granted(\'ROLE_HUMAN\')',
            uriTemplate: '/orders/{id}/replace-products',
            controller: ReplaceProductsOrderAction::class,
            denormalizationContext: ['groups' => ['order:write']],
            normalizationContext: ['groups' => ['order_details:read']],
        ),

    ],
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['order:read']],
    denormalizationContext: ['groups' => ['order:write']],
    // AleMac // 06/12/2025 // ordenação padrão alterada para alterDate
    order: ['alterDate' => 'DESC', 'id', 'orderDate', 'provider', 'app', 'orderType', 'status', 'client']
)]

#[ApiFilter(OrderFilter::class, properties: [
    'id',
    'app',
    'orderType',
    'client.name',
    'client.alias',
    'provider.name',
    'provider.alias',
    'status.status',
    'status.realStatus',
    'orderDate',
    'alterDate',
    'price',
    'comments',
    'notified',
    'mainOrderId'
])]
#[ApiFilter(CustomOrFilter::class, properties: [
    'id',
    'app',
    'orderType',
    'comments',
    'client.name',
    'client.alias',
    'provider.name',
    'provider.alias',
    'deliveryContact.name',
    'deliveryContact.alias',
    'status.status',
    'status.realStatus',
    'addressOrigin.nickname',
    'addressDestination.nickname'
])]
#[ApiFilter(SearchFilter::class, properties: ['orderProducts.product' => 'exact'])]
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
#[ORM\Index(name: 'cancellation_reason_id', columns: ['cancellation_reason_id'])]
#[ORM\Index(name: 'canceled_by_id', columns: ['canceled_by_id'])]
#[ORM\Index(name: 'IDX_E52FFDEEDB805178', columns: ['quote_id'])]
#[ORM\UniqueConstraint(name: 'discount_id', columns: ['discount_coupon_id'])]

#[ORM\Entity(repositoryClass: OrderRepository::class)]
class Order
{
    use OrderAccessors1, OrderAccessors2;

    public const APP_IFOOD = 'iFood';
    public const APP_FOOD99 = 'Food99';
    public const APP_MERCADO_LIVRE = 'MercadoLivre';
    public const CHANNEL_POS = 'pos';
    public const CHANNEL_SHOP = 'shop';
    public const CHANNEL_TOTEM = 'totem';
    public const CHANNEL_EXTERNAL = 'external';
    public const CHANNELS = [
        self::CHANNEL_POS,
        self::CHANNEL_SHOP,
        self::CHANNEL_TOTEM,
        self::CHANNEL_EXTERNAL,
    ];
    public const FULFILLMENT_DINE_IN = 'dine_in';
    public const FULFILLMENT_PICKUP = 'pickup';
    public const FULFILLMENT_COUNTER = 'counter';
    public const FULFILLMENT_DELIVERY = 'delivery';
    public const FULFILLMENT_SHIPPING = 'shipping';
    public const FULFILLMENT_TYPES = [
        self::FULFILLMENT_DINE_IN,
        self::FULFILLMENT_PICKUP,
        self::FULFILLMENT_COUNTER,
        self::FULFILLMENT_DELIVERY,
        self::FULFILLMENT_SHIPPING,
    ];
    public const ORDER_TYPE_CART = 'cart';
    public const ORDER_TYPE_QUOTE = 'quote';
    public const ORDER_TYPE_DELIVERY = 'delivery';
    public const ORDER_TYPE_SALE = 'sale';
    public const ORDER_TYPE_TAB = 'tab';
    public const ORDER_TYPE_TABLE = 'table';
    public const ORDER_TYPE_STAMP = 'stamp';
    public const ORDER_TYPE_PURCHASE = 'purchase';
    public const ORDER_TYPE_FIDELITY = 'fidelity';

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['id' => 'exact'])]
    #[ORM\Column(name: 'id', type: 'integer', nullable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'company_expense:read', 'coupon:read', 'logistic:read', 'order_invoice:read', 'tracking:read'])]
    private $id;

    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'company_expense:read', 'coupon:read', 'logistic:read', 'order_invoice:read'])]
    private $extraData = null;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['client' => 'exact'])]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: People::class)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'invoice:read', 'order_invoice:read'])]
    private $client;

    // Registro do documento comercial ligado ao pedido. No CRM esse vínculo
    // aponta primeiro para a proposta; o contrato final pode surgir depois.
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['contract' => 'exact'])]
    #[ORM\JoinColumn(name: 'contract_id', referencedColumnName: 'id', nullable: true)]
    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[Groups(['order:read', 'order_details:read', 'order:write', 'logistic:read'])]
    private $contract;

    #[ApiFilter(DateFilter::class, properties: ['orderDate'])]
    #[ORM\Column(name: 'order_date', type: 'datetime', nullable: false, columnDefinition: 'DATETIME')]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write', 'order_invoice:read', 'tracking:read'])]
    private $orderDate;

    #[ORM\OneToMany(targetEntity: OrderProduct::class, mappedBy: 'order', cascade: ['persist'])]
    #[Groups(['order_creation:read', 'order_conference:read', 'order_details:read', 'orders-queue:read', 'order:write', 'order:write', 'tracking:read'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['orderProducts.orderProductQueues.status' => 'exact'])]
    private $orderProducts;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['invoice' => 'exact'])]
    #[ORM\OneToMany(targetEntity: OrderInvoice::class, mappedBy: 'order')]
    private $invoice;

    #[ORM\OneToMany(targetEntity: OrderFile::class, mappedBy: 'order', cascade: ['persist'])]
    #[Groups(['order_details:read', 'order:write'])]
    private $orderFiles;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['task' => 'exact'])]
    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'order')]
    private $task;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['invoiceTax' => 'exact'])]
    #[ORM\OneToMany(targetEntity: OrderInvoiceTax::class, mappedBy: 'order')]
    private $invoiceTax;

    #[ApiFilter(DateFilter::class, properties: ['alterDate'])]
    #[ORM\Column(name: 'alter_date', type: 'datetime', nullable: false)]
    #[Groups(['display:read', 'order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write', 'order_invoice:read'])]
    private $alterDate;

    #[CollectionSummary(
        name: 'report',
        parameter: 'report',
        parameterValue: '1',
        groups: ['order:read'],
        resolver: OrderReportSummaryResolver::class
    )]
    private $reportSummary = null;

    #[CollectionSummary(
        name: 'sales',
        parameter: 'summary',
        parameterValue: 'sales',
        groups: ['order:read'],
        resolver: OrderSalesSummaryResolver::class
    )]
    private $salesSummary = null;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['status' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['status.realStatus' => 'exact'])]
    #[ORM\JoinColumn(name: 'status_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: Status::class)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'display:read', 'order:read', 'order_details:read', 'order:write', 'order:write', 'order_invoice:read', 'tracking:read'])]
    private $status;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['orderType' => 'exact'])]
    #[ORM\Column(name: 'order_type', type: 'string', nullable: true)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'display:read', 'order:read', 'order_details:read', 'order:write', 'order:write', 'order_invoice:read', 'tracking:read'])]
    private $orderType;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['app' => 'exact'])]
    #[ORM\Column(name: 'app', type: 'string', nullable: true)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'display:read', 'order:read', 'order_details:read', 'order:write', 'order:write', 'order_invoice:read', 'tracking:read'])]
    private $app = 'POS';

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['channel' => 'exact'])]
    #[ORM\Column(name: 'channel', type: 'string', length: 16, nullable: true)]
    #[Assert\Choice(choices: self::CHANNELS)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'display:read', 'order:read', 'order_details:read', 'order:write', 'order_invoice:read', 'tracking:read'])]
    private ?string $channel = null;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['fulfillmentType' => 'exact'])]
    #[ORM\Column(name: 'fulfillment_type', type: 'string', length: 16, nullable: true)]
    #[Assert\Choice(choices: self::FULFILLMENT_TYPES)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'order_invoice:read', 'tracking:read'])]
    private ?string $fulfillmentType = null;

    #[ORM\Column(name: 'pay_before_production', type: 'boolean', nullable: true)]
    #[Groups(['order:read', 'order_details:read', 'order_invoice:read', 'tracking:read'])]
    private ?bool $payBeforeProduction = null;

    #[ORM\Column(name: 'operational_snapshot', type: 'json', nullable: true)]
    #[Groups(['order:read', 'order_details:read', 'order_invoice:read', 'tracking:read'])]
    private ?array $operationalSnapshot = null;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['externalCode' => 'exact'])]
    #[ORM\Column(name: 'external_code', type: 'string', length: 255, nullable: true)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'display:read', 'order:read', 'order_details:read', 'order:write', 'order_invoice:read', 'tracking:read'])]
    private $externalCode;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['otherInformations' => 'exact'])]
    #[ORM\Column(name: 'other_informations', type: 'json', nullable: true)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $otherInformations;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['cancellationReason' => 'exact'])]
    #[ORM\JoinColumn(name: 'cancellation_reason_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order_invoice:read'])]
    private $cancellationReason;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['canceledBy' => 'exact'])]
    #[ORM\JoinColumn(name: 'canceled_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: People::class)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order_invoice:read'])]
    private $canceledBy;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['mainOrder' => 'exact'])]
    #[ORM\JoinColumn(name: 'main_order_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[Groups(['order:read'])]
    private $mainOrder;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['mainOrderId' => 'exact'])]
    #[ORM\Column(name: 'main_order_id', type: 'integer', nullable: true)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $mainOrderId;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['payer' => 'exact'])]
    #[ORM\JoinColumn(name: 'payer_people_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: People::class)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'invoice:read', 'order_invoice:read'])]
    private $payer;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['provider' => 'exact'])]
    #[ORM\JoinColumn(name: 'provider_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: People::class)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'invoice:read', 'order_invoice:read'])]
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
    #[Groups(['order:read', 'order_details:read'])]
    private $deliveryContact;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['deliveryPeople' => 'exact'])]
    #[ORM\JoinColumn(name: 'delivery_people_id', referencedColumnName: 'id', nullable: true)]
    #[ORM\ManyToOne(targetEntity: People::class)]
    // Logistics-only relation; keep it out of the common order read groups.
    #[Groups(['logistic:read'])]
    private $deliveryPeople;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['price' => 'exact'])]
    #[ORM\Column(name: 'price', type: 'float', nullable: false)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write', 'order_invoice:read'])]
    private $price = 0;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['comments' => 'exact'])]
    #[ORM\Column(name: 'comments', type: 'string', nullable: true)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
    private $comments;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['notified' => 'exact'])]
    #[ORM\Column(name: 'notified', type: 'boolean')]
    private $notified = false;

    #[ApiFilter(filterClass: SearchFilter::class, properties: ['user' => 'exact'])]
    #[ORM\JoinColumn(nullable: true)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[Groups(['order_product_queue:read', 'orders-queue:read', 'display:read', 'order:read', 'order_details:read', 'order:write', 'order:write'])]
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
        $this->orderFiles = new ArrayCollection();
        $this->task = new ArrayCollection();
        $this->orderProducts = new ArrayCollection();
        $this->otherInformations = json_encode(new stdClass());
    }

    // Request-scoped read projection. Never persisted or accepted in order:write.
    #[Groups(['order_details:read'])]
    private ?array $chargeCapability = null;

}
