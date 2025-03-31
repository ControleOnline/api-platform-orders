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
 * ProductComponent
 *
 * @ORM\EntityListeners({ControleOnline\Listener\LogListener::class})
 * @ORM\Table(name="order_product")
 * @ORM\Entity(repositoryClass="ControleOnline\Repository\OrderProductRepository")
 */
#[ApiResource(
    operations: [
        new Get(
            security: 'is_granted(\'ROLE_ADMIN\') or is_granted(\'ROLE_CLIENT\')',
        ),
        new GetCollection(
            security: 'is_granted(\'ROLE_ADMIN\') or is_granted(\'ROLE_CLIENT\')',
        ),
        new Post(
            security: 'is_granted(\'ROLE_ADMIN\') or is_granted(\'ROLE_CLIENT\')',
        ),
        new Put(
            security: 'is_granted(\'ROLE_ADMIN\') or is_granted(\'ROLE_CLIENT\')',
        ),
        new Delete(
            security: 'is_granted(\'ROLE_ADMIN\') or is_granted(\'ROLE_CLIENT\')',
        ),
    ],
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['product_components:read']],
    denormalizationContext: ['groups' => ['product_components:write']]
)]
#[ApiFilter(filterClass: OrderFilter::class, properties: ['alterDate' => 'DESC'])]
#[ApiFilter(OrderFilter::class, properties: ['id' => 'ASC', 'parentProduct' => 'ASC'])]
class ProductComponent
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * @Groups({"product_components:read", "order_product:read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['id' => 'exact'])]
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Order")
     * @ORM\JoinColumn(nullable=false)
     * @Groups({"product_components:read", "product_components:write", "order_product:read", "order_product:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['order' => 'exact'])]
    private $order;

    /**
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Product")
     * @ORM\JoinColumn(nullable=false)
     * @Groups({"product_components:read", "product_components:write", "order_product:read", "order_product:write"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['product' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['product.type' => 'exact'])]
    private $product;

    /**
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Product")
     * @ORM\JoinColumn(name="parent_product_id", referencedColumnName="id", nullable=true)
     * @Groups({"product_components:read", "product_components:write"})
     */
    #[ApiFilter(ExistsFilter::class, properties: ['parentProduct'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['parentProduct' => 'exact'])]
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['parentProduct.type' => 'exact'])]
    private $parentProduct;

    /**
     * @ORM\Column(type="float")
     * @Groups({"product_components:read", "product_components:write", "order_product:read", "order_product:write"})
     */
    private $quantity = 1;

    /**
     * @ORM\Column(type="float")
     * @Groups({"product_components:read", "product_components:write", "order_product:read", "order_product:write"})
     */
    private $price = 0;

    /**
     * @ORM\Column(type="float")
     * @Groups({"product_components:read", "product_components:write", "order_product:read", "order_product:write"})
     */
    private $total = 0;

    public function __construct()
    {
    }

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
}