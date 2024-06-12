<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Status;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;

class OrderInvoiceService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security
    ) {
    }

    public function afterPersist(OrderInvoice $OrderInvoice)
    {
        $invoice = $OrderInvoice->getInvoice();
        $order = $OrderInvoice->getOrder();
        if ($invoice->getStatus()->getStatus() == 'Pago') {
            $orderStatus = $this->manager->getRepository(Status::class)->findOneBy([
                'status' => 'Pago',
                'context' => 'order'
            ]);
            $order->setStatus($orderStatus);
            $this->manager->persist($order);
            $this->manager->flush();
        }
        return $order;
    }
}
