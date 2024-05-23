<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\OrderProduct;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;

class OrderProductService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security
    ) {
    }

    public function afterPersist(OrderProduct $OrderProduct)
    {
        $order = $OrderProduct->getOrder();

        $sql = 'SELECT SUM(price * quantity) as total FROM order_product WHERE order_id = :order_id';
        $connection = $this->manager->getConnection();
        $statement = $connection->prepare($sql);
        $statement->execute(['order_id' =>  $order->getId()]);

        $result = $statement->fetchOne();
        $order->setPrice($result);
        $this->manager->persist($order);
        $this->manager->flush();
        return $order;
    }
}
