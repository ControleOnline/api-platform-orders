<?php

namespace ControleOnline\Entity;

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
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiProperty;

/**
 * OrderProduct
 *
 * @ORM\EntityListeners({ControleOnline\Listener\LogListener::class})
 * @ORM\Table(name="order_product")
 * @ORM\Entity(repositoryClass="ControleOnline\Repository\OrderProductRepository")
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
class OrderProduct
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","order_product:write","order_product:read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['id' => 'exact'])]
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Order")
     * @ORM\JoinColumn(nullable=false)
     * @Groups({"order_product_queue:read","order_product:write","order_product:read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['order' => 'exact'])]
    private $order;

    /**
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Product")
     * @ORM\JoinColumn(nullable=false)
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","order_product:write","order_product:read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['product' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['product.type' => 'exact'])]
    private $product;

    /**
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Product")
     * @ORM\JoinColumn(nullable=true)
     * @Groups({"order_product:write","order_product:read"})
     */
    #[ApiFilter(ExistsFilter::class, properties: ['parentProduct'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['parentProduct' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['parentProduct.type' => 'exact'])]
    private $parentProduct;

    /**
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\OrderProduct")
     * @ORM\JoinColumn(nullable=true)
     * @Groups({"order_product:write","order_product:read"})
     */
    #[ApiFilter(ExistsFilter::class, properties: ['orderProduct'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['orderProduct' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['orderProduct.type' => 'exact'])]
    private $orderProduct;

    /**
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\ProductGroup")
     * @ORM\JoinColumn(nullable=true)
     * @Groups({"order_product:write","order_product:read"})
     */
    #[ApiFilter(ExistsFilter::class, properties: ['productGroup'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['productGroup' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['productGroup.type' => 'exact'])]
    private $productGroup;

    /**
     * @ORM\OneToMany(targetEntity="ControleOnline\Entity\OrderProductQueue", mappedBy="order_product")
     * @Groups({"order_product:read"})
     */
    private $orderProductQueues;

    /**
     * @ORM\Column(type="float")
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","order_product:write","order_product:read"})
     */
    private $quantity = 1;

    /**
     * @ORM\Column(type="float")
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","order_product:write","order_product:read"})
     */
    private $price = 0;

    /**
     * @ORM\Column(type="float")
     * @Groups({"order_product_queue:read","order:read","order_details:read","order:write","order_product:write","order_product:read"})
     */
    private $total = 0;

    public function __construct()
    {
        $this->orderProductQueues = new ArrayCollection();
    }

    // Getters and setters

    /**
     * Get the value of id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set the value of id
     */
    public function setId($id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get the value of order
     */
    public function getOrder()
    {
        return $this->order;
    }

    /**
     * Set the value of order
     */
    public function setOrder($order): self
    {
        $this->order = $order;
        return $this;
    }

    /**
     * Get the value of product
     */
    public function getProduct()
    {
        return $this->product;
    }

    /**
     * Set the value of product
     */
    public function setProduct($product): self
    {
        $this->product = $product;
        return $this;
    }

    /**
     * Get the value of quantity
     */
    public function getQuantity()
    {
        return $this->quantity;
    }

    /**
     * Set the value of quantity
     */
    public function setQuantity($quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    /**
     * Get the value of price
     */
    public function getPrice()
    {
        return $this->price;
    }

    /**
     * Set the value of price
     */
    public function setPrice($price): self
    {
        $this->price = $price;
        return $this;
    }

    /**
     * Get the value of total
     */
    public function getTotal()
    {
        return $this->total;
    }

    /**
     * Set the value of total
     */
    public function setTotal($total): self
    {
        $this->total = $total;
        return $this;
    }

    /**
     * Get the value of parentProduct
     */
    public function getParentProduct()
    {
        return $this->parentProduct;
    }

    /**
     * Set the value of parentProduct
     */
    public function setParentProduct($parentProduct): self
    {
        $this->parentProduct = $parentProduct;
        return $this;
    }

    /**
     * Get the value of orderProduct
     */
    public function getOrderProduct()
    {
        return $this->orderProduct;
    }

    /**
     * Set the value of orderProduct
     */
    public function setOrderProduct($orderProduct): self
    {
        $this->orderProduct = $orderProduct;
        return $this;
    }

    /**
     * Get the value of productGroup
     */
    public function getProductGroup()
    {
        return $this->productGroup;
    }

    /**
     * Set the value of productGroup
     */
    public function setProductGroup($productGroup): self
    {
        $this->productGroup = $productGroup;
        return $this;
    }

    /**
     * Get the value of orderProductQueues
     */
    public function getOrderProductQueues()
    {
        return $this->orderProductQueues;
    }

    /**
     * Add an OrderProductQueue
     */
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
}