<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Status;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
 AS Security;

class OrderInvoiceService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
    ) {}

    public function postPersist(OrderInvoice $OrderInvoice) {}
}
