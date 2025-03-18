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
        private Security $security,
    ) {}

    public function postPersist(OrderInvoice $OrderInvoice) {}
}
