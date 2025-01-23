<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

class OrderService
{
    private $request;

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $PeopleService,
        RequestStack $requestStack

    ) {
        $this->request  = $requestStack->getCurrentRequest();
    }


    public function createOrder(People $receiver, People $payer)
    {

        $order = new Order();
        $order->setProvider($receiver);
        $order->setClient($payer);
        $order->setPayer($payer);
        $order->setOrderType('sales');
        $order->setStatus($this->manager->getRepository(Status::class)->findOneBy([
            'status' => 'open',
            'context' => 'order',
            'people' => $receiver
        ]));
        $order->setApp('Asaas');
        $this->manager->persist($order);
        $this->manager->flush();
        return $order;
    }

    public function secutiryFilter(QueryBuilder $queryBuilder, $resourceClass = null, $applyTo = null, $rootAlias = null): void
    {
        $companies   = $this->PeopleService->getMyCompanies();

        if ($invoice = $this->request->query->get('invoiceId', null)) {
            $queryBuilder->join(sprintf('%s.invoice', $rootAlias), 'OrderInvoice');
            $queryBuilder->andWhere(sprintf('OrderInvoice.invoice IN(:invoice)', $rootAlias, $rootAlias));
            $queryBuilder->setParameter('invoice', $invoice);
        }

        $queryBuilder->andWhere(sprintf('%s.client IN(:companies) OR %s.provider IN(:companies)', $rootAlias, $rootAlias));
        $queryBuilder->setParameter('companies', $companies);

        if ($provider = $this->request->query->get('provider', null)) {
            $queryBuilder->andWhere(sprintf('%s.provider IN(:provider)', $rootAlias));
            $queryBuilder->setParameter('provider', preg_replace("/[^0-9]/", "", $provider));
        }

        if ($client = $this->request->query->get('client', null)) {
            $queryBuilder->andWhere(sprintf('%s.client IN(:client)', $rootAlias));
            $queryBuilder->setParameter('client', preg_replace("/[^0-9]/", "", $client));
        }
    }
}
