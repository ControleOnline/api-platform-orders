<?php

namespace ControleOnline\Entity;

use Symfony\Component\Serializer\Attribute\Groups;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ControleOnline\Repository\OrderFileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ApiResource(
    operations: [
        new Get(
            security: 'is_granted(\'ROLE_HUMAN\')',
            normalizationContext: ['groups' => ['order_file:read']],
        ),
        new Put(
            security: 'is_granted(\'ROLE_HUMAN\')',
            denormalizationContext: ['groups' => ['order_file:write']],
        ),
        new Delete(security: 'is_granted(\'ROLE_HUMAN\')'),
        new Post(
            security: 'is_granted(\'ROLE_HUMAN\')',
            securityPostDenormalize: 'is_granted(\'ROLE_HUMAN\')',
        ),
        new GetCollection(security: 'is_granted(\'ROLE_HUMAN\')'),
    ],
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['order_file:read']],
    denormalizationContext: ['groups' => ['order_file:write']],
)]
#[ApiFilter(filterClass: SearchFilter::class, properties: [
    'order' => 'exact',
    'file' => 'exact',
    'file.fileName' => 'exact',
    'file.fileType' => 'exact',
])]
#[ORM\Table(name: 'order_file')]
#[ORM\Index(name: 'order_file_order_id_idx', columns: ['order_id'])]
#[ORM\Index(name: 'order_file_file_id_idx', columns: ['file_id'])]
#[ORM\UniqueConstraint(name: 'order_file_unique', columns: ['order_id', 'file_id'])]
#[ORM\Entity(repositoryClass: OrderFileRepository::class)]
class OrderFile
{
    #[ORM\Column(name: 'id', type: 'integer', nullable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[Groups(['order_details:read', 'order_file:read'])]
    private int $id = 0;

    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'orderFiles')]
    #[Groups(['order_file:write'])]
    private ?Order $order = null;

    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: File::class)]
    #[Groups(['order_details:read', 'order_file:read', 'order_file:write'])]
    private ?File $file = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): self
    {
        $this->order = $order;
        return $this;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFile(?File $file): self
    {
        $this->file = $file;
        return $this;
    }
}
