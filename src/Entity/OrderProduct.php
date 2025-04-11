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
use stdClass;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\NumericFilter;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiProperty;

/**
 * OrderProduct
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
        ),
        new Put(
            security: 'is_granted(\'ROLE_ADMIN\') or (is_granted(\'ROLE_CLIENT\'))',
        ),
        new Delete(
            security: 'is_granted(\'ROLE_ADMIN\') or (is_granted(\'ROLE_CLIENT\'))',
        ),
    ],
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['order_product:read']],
    denormalizationContext: ['groups' => ['order_product:write']]
)]
#[ApiFilter(filterClass: OrderFilter::class, properties: ['alterDate' => 'DESC'])]
#[ApiFilter(OrderFilter::class, properties: ['id' => 'ASC', 'product.product' => 'ASC'])]
#[ApiFilter(NumericFilter::class, properties: ['order.id'])]
#[ORM\Table(name: 'order_product')]
#[ORM\EntityListeners([LogListener::class])]
#[ORM\Entity(repositoryClass: \ControleOnline\Repository\OrderProductRepository::class)]
class OrderProduct
{
    /**
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","order_product:write","order_product:read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['id' => 'exact'])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    /**
     * @Groups({"order_product_queue:read","order_product:write","order_product:read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['order' => 'exact'])]
    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Order::class)]
    private $order;

    /**
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","order_product:write","order_product:read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['product' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['product.type' => 'exact'])]
    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Product::class)]
    private $product;

    /**
     * @Groups({"order_product:write","order_product:read"})
     */
    #[ApiFilter(ExistsFilter::class, properties: ['inInventory'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['inInventory' => 'exact'])]
    #[ORM\JoinColumn(name: 'in_inventory_id', referencedColumnName: 'id', nullable: true)]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Inventory::class)]
    private $inInventory;

    /**
     * @Groups({"order_product:write","order_product:read"})
     */
    #[ApiFilter(ExistsFilter::class, properties: ['outInventory'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['outInventory' => 'exact'])]
    #[ORM\JoinColumn(name: 'out_inventory_id', referencedColumnName: 'id', nullable: true)]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Inventory::class)]
    private $outInventory;

    /**
     * @Groups({"order_product:write","order_product:read"})
     */
    #[ApiFilter(ExistsFilter::class, properties: ['parentProduct'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['parentProduct' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['parentProduct.type' => 'exact'])]
    #[ORM\JoinColumn(name: 'parent_product_id', referencedColumnName: 'id', nullable: true)]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\Product::class)]
    private $parentProduct;

    /**
     * @Groups({"order_product:write","order_product:read"})
     */
    #[ApiFilter(ExistsFilter::class, properties: ['orderProduct'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['orderProduct' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['orderProduct.type' => 'exact'])]
    #[ORM\JoinColumn(name: 'order_product_id', nullable: true)]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\OrderProduct::class, inversedBy: 'orderProductComponents')]
    private $orderProduct;

    /**
     * @Groups({"order_product:write","order_product:read"})
     */
    #[ApiFilter(ExistsFilter::class, properties: ['productGroup'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['productGroup' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['productGroup.type' => 'exact'])]
    #[ORM\JoinColumn(nullable: true)]
    #[ORM\ManyToOne(targetEntity: \ControleOnline\Entity\ProductGroup::class)]
    private $productGroup;

    /**
     * @Groups({"order_product:read", "order_product:write"})
     */
    #[ORM\OneToMany(targetEntity: \ControleOnline\Entity\OrderProduct::class, mappedBy: 'orderProduct')]
    private $orderProductComponents;

    /**
     * @Groups({"order_product:read"})
     */
    #[ORM\OneToMany(targetEntity: \ControleOnline\Entity\OrderProductQueue::class, mappedBy: 'order_product')]
    private $orderProductQueues;

    /**
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","order_product:write","order_product:read"})
     */
    #[ORM\Column(type: 'float')]
    private $quantity = 1;

    /**
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","order_product:write","order_product:read"})
     */
    #[ORM\Column(type: 'float')]
    private $price = 0;

    /**
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","order_product:write","order_product:read"})
     */
    #[ORM\Column(type: 'float')]
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