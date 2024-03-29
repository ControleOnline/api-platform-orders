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
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiProperty;

/**
 *  OrderProduct
 *
 * @ORM\EntityListeners({App\Listener\LogListener::class})
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
            validationContext: ['groups' => ['order_product_write']],
            denormalizationContext: ['groups' => ['order_product_write']]
        ),
        new Put(
            security: 'is_granted(\'ROLE_ADMIN\') or (is_granted(\'ROLE_CLIENT\'))',
            validationContext: ['groups' => ['order_product_write']],
            denormalizationContext: ['groups' => ['order_product_write']]
        ),
    ],
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['order_product_read']],
    denormalizationContext: ['groups' => ['order_product_write']]
)]
#[ApiFilter(filterClass: OrderFilter::class, properties: ['alterDate' => 'DESC'])]

class OrderProduct
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * @Groups({"order_read","order_product_write","order_product_read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['id' => 'exact'])]
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Order")
     * @ORM\JoinColumn(nullable=false)
     * @Groups({"order_product_write","order_product_read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['id' => 'exact'])]
    private $order;

    /**
     * @ORM\ManyToOne(targetEntity="ControleOnline\Entity\Product")
     * @ORM\JoinColumn(nullable=false)
     * @Groups({"order_read","order_product_write","order_product_read"})
     */
    #[ApiFilter(filterClass: SearchFilter::class, properties: ['id' => 'exact'])]
    private $product;

    /**
     * @ORM\Column(type="float")
     * @Groups({"order_read","order_product_write","order_product_read"})
     */
    private $quantity = 1;

    /**
     * @ORM\Column(type="float")
     * @Groups({"order_read","order_product_write","order_product_read"})
     */
    private $price;

    /**
     * @ORM\Column(type="float")
     * @Groups({"order_read","order_product_write","order_product_read"})
     */
    private $total;

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
}
