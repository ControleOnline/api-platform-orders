<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\{Product, ProductGroupParent, ProductGroupProduct};
use Doctrine\ORM\EntityManagerInterface;

/** Catalog reads reused only within one order group-link normalization pass. */
final class OrderProductGroupLinkCandidates
{
    private array $candidates = [];

    public function __construct(private EntityManagerInterface $manager) {}

    /** @return list<ProductGroupProduct> */
    public function get(Product $parentProduct, Product $childProduct): array
    {
        $key = spl_object_id($parentProduct) . ':' . spl_object_id($childProduct);
        if (array_key_exists($key, $this->candidates)) {
            return $this->candidates[$key];
        }

        $repository = $this->manager->getRepository(ProductGroupProduct::class);
        /*
         * @agents Prefer the hidden queue mapping when the same child exists in more than one group.
         */
        $directLinkCandidates = $repository->createQueryBuilder('groupProduct')
            ->andWhere('groupProduct.product = :parentProduct')
            ->andWhere('groupProduct.productChild = :childProduct')
            ->andWhere('groupProduct.active = true')
            ->setParameter('parentProduct', $parentProduct)
            ->setParameter('childProduct', $childProduct)
            ->getQuery()
            ->getResult();

        $groupLinkCandidates = $repository->createQueryBuilder('groupProduct')
            ->innerJoin(
                ProductGroupParent::class,
                'groupParent',
                'WITH',
                'groupParent.productGroup = groupProduct.productGroup'
            )
            ->andWhere('groupProduct.productChild = :childProduct')
            ->andWhere('groupProduct.active = true')
            ->andWhere('groupParent.parentProduct = :parentProduct')
            ->andWhere('groupParent.active = true')
            ->setParameter('childProduct', $childProduct)
            ->setParameter('parentProduct', $parentProduct)
            ->getQuery()
            ->getResult();

        return $this->candidates[$key] = array_merge($directLinkCandidates, $groupLinkCandidates);
    }
}
