<?php

namespace ControleOnline\Repository;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderQrContext;
use ControleOnline\Entity\People;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderQrContext>
 */
class OrderQrContextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderQrContext::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?OrderQrContext
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Find the open root order for a table/tab external code under a provider.
     * "Open" = status realStatus is not a closed/cancelled terminal state when available.
     */
    public function findOpenRootByProviderAndExternalCode(People $provider, string $externalCode): ?Order
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('o')
            ->from(Order::class, 'o')
            ->leftJoin('o.status', 's')
            ->where('o.provider = :provider')
            ->andWhere('o.externalCode = :externalCode')
            ->andWhere('o.mainOrder IS NULL')
            ->setParameter('provider', $provider)
            ->setParameter('externalCode', $externalCode)
            ->orderBy('o.id', 'DESC')
            ->setMaxResults(20);

        /** @var Order[] $candidates */
        $candidates = $qb->getQuery()->getResult();
        foreach ($candidates as $order) {
            if ($this->isOrderOpenRoot($order)) {
                return $order;
            }
        }

        return null;
    }

    public function isOrderOpenRoot(Order $order): bool
    {
        if ($order->getMainOrder() !== null) {
            return false;
        }

        $status = $order->getStatus();
        if ($status === null) {
            return true;
        }

        $real = strtolower(trim((string) (method_exists($status, 'getRealStatus') ? $status->getRealStatus() : '')));
        $name = strtolower(trim((string) (method_exists($status, 'getStatus') ? $status->getStatus() : '')));

        $closedMarkers = [
            'closed', 'cancelled', 'canceled', 'done', 'finished', 'delivered',
            'paid_closed', 'archived', 'void',
        ];

        foreach ($closedMarkers as $marker) {
            if ($real === $marker || $name === $marker) {
                return false;
            }
            if (str_contains($real, $marker) || str_contains($name, $marker)) {
                return false;
            }
        }

        return true;
    }
}
