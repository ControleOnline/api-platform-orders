<?php

namespace ControleOnline\Entity;

use Symfony\Component\Serializer\Attribute\Groups;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\Inventory;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\OrderProductQueue;
use ControleOnline\Repository\OrderProductRepository;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\NumericFilter;

#[ORM\Table(name: 'order_product')]

#[ORM\Entity(repositoryClass: OrderProductRepository::class)]
#[ApiResource(
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['order_product:read']],
    denormalizationContext: ['groups' => ['order_product:write']],
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')"),
        new Get(security: "is_granted('ROLE_CLIENT')"),
        new Post(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')"),
        new Put(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')"),
        new Delete(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')")
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['alterDate' => 'DESC', 'id' => 'ASC', 'product.product' => 'ASC'])]
#[ApiFilter(NumericFilter::class, properties: ['order.id'])]
#[ApiFilter(SearchFilter::class, properties: [
    'id' => 'exact',
    'order' => 'exact',
    'product' => 'exact',
    'product.type' => 'exact',
    'inInventory' => 'exact',
    'outInventory' => 'exact',
    'parentProduct' => 'exact',
    'parentProduct.type' => 'exact',
    'orderProduct' => 'exact',
    'orderProduct.type' => 'exact',
    'productGroup' => 'exact',
    'productGroup.type' => 'exact'
])]
#[ApiFilter(ExistsFilter::class, properties: [
    'inInventory',
    'outInventory',
    'parentProduct',
    'orderProduct',
    'productGroup'
])]
class OrderProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write',  'order_product:write', 'order_product:read'])]
    private $id;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['order_product_queue:read', 'order_product:write', 'order_product:read'])]
    private $order;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write',  'order_product:write', 'order_product:read'])]
    private $product;

    #[ORM\ManyToOne(targetEntity: Inventory::class)]
    #[ORM\JoinColumn(name: 'in_inventory_id', referencedColumnName: 'id', nullable: true)]
    #[Groups(['order_product:write', 'order_product:read'])]
    private $inInventory;

    #[ORM\ManyToOne(targetEntity: Inventory::class)]
    #[ORM\JoinColumn(name: 'out_inventory_id', referencedColumnName: 'id', nullable: true)]
    #[Groups(['order_product:write', 'order_product:read'])]
    private $outInventory;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'parent_product_id', referencedColumnName: 'id', nullable: true)]
    #[Groups(['order_product:write', 'order_product:read'])]
    private $parentProduct;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'orderProductComponents')]
    #[ORM\JoinColumn(name: 'order_product_id', nullable: true)]
    #[Groups(['order_product:write', 'order_product:read'])]
    private $orderProduct;

    #[ORM\ManyToOne(targetEntity: ProductGroup::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['order_product:write', 'order_product:read'])]
    private $productGroup;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'orderProduct')]
    #[Groups(['order_product:write', 'order_product:read'])]
    private $orderProductComponents;

    #[ORM\OneToMany(targetEntity: OrderProductQueue::class, mappedBy: 'order_product')]
    #[Groups(['order_product:read', 'order_details:read'])]
    private $orderProductQueues;

    #[ORM\Column(type: 'float')]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write',  'order_product:write', 'order_product:read'])]
    private $quantity = 1;

    #[ORM\Column(type: 'float')]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write',  'order_product:write', 'order_product:read'])]
    private $price = 0;

    #[ORM\Column(type: 'float')]
    #[Groups(['order_product_queue:read', 'order:read', 'order_details:read', 'order:write',  'order_product:write', 'order_product:read'])]
    private $total = 0;

    public function __construct()
    {
        $this->orderProductQueues = new ArrayCollection();
        $this->orderProductComponents = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function setOrder($order): self
    {
        $this->order = $order;
        return $this;
    }

    public function getProduct()
    {
        return $this->product;
    }

    public function setProduct($product): self
    {
        $this->product = $product;
        return $this;
    }

    public function getInInventory()
    {
        return $this->inInventory;
    }

    public function setInInventory($inInventory): self
    {
        $this->inInventory = $inInventory;
        return $this;
    }

    public function getOutInventory()
    {
        return $this->outInventory;
    }

    public function setOutInventory($outInventory): self
    {
        $this->outInventory = $outInventory;
        return $this;
    }

    public function getParentProduct()
    {
        return $this->parentProduct;
    }

    public function setParentProduct($parentProduct): self
    {
        $this->parentProduct = $parentProduct;
        return $this;
    }

    public function getOrderProduct()
    {
        return $this->orderProduct;
    }

    public function setOrderProduct(?OrderProduct $orderProduct): self
    {
        $this->orderProduct = $orderProduct;
        return $this;
    }

    public function getProductGroup()
    {
        return $this->productGroup;
    }

    public function setProductGroup($productGroup): self
    {
        $this->productGroup = $productGroup;
        return $this;
    }

    public function getOrderProductQueues()
    {
        return $this->orderProductQueues;
    }

    public function addOrderProductQueue(OrderProductQueue $orderProductQueue): self
    {
        if (!$this->orderProductQueues->contains($orderProductQueue)) {
            $this->orderProductQueues[] = $orderProductQueue;
            $orderProductQueue->setOrderProduct($this);
        }
        return $this;
    }

    public function removeOrderProductQueue(OrderProductQueue $orderProductQueue): self
    {
        if ($this->orderProductQueues->removeElement($orderProductQueue)) {
            if ($orderProductQueue->getOrderProduct() === $this) {
                $orderProductQueue->setOrderProduct(null);
            }
        }
        return $this;
    }

    public function getOrderProductComponents()
    {
        return $this->orderProductComponents;
    }

    public function addOrderProductComponent(OrderProduct $orderProductComponent): self
    {
        if (!$this->orderProductComponents->contains($orderProductComponent)) {
            $this->orderProductComponents[] = $orderProductComponent;
            $orderProductComponent->setOrderProduct($this);
        }
        return $this;
    }

    public function removeOrderProductComponent(OrderProduct $orderProductComponent): self
    {
        if ($this->orderProductComponents->removeElement($orderProductComponent)) {
            if ($orderProductComponent->getOrderProduct() === $this) {
                $orderProductComponent->setOrderProduct(null);
            }
        }
        return $this;
    }

    public function getQuantity()
    {
        return $this->quantity;
    }

    public function setQuantity($quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getPrice()
    {
        return $this->price;
    }

    public function setPrice($price): self
    {
        $this->price = $price;
        return $this;
    }

    public function getTotal()
    {
        return $this->total;
    }

    public function setTotal($total): self
    {
        $this->total = $total;
        return $this;
    }
}
