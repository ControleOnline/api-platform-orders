<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Doctrine\DBAL\Types\Type;

class OrderService
{
    private $request;

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $peopleService,
        RequestStack $requestStack
    ) {
        $this->request  = $requestStack->getCurrentRequest();
    }

    public function calculateOrderPrice(Order $order)
    {
        $sql = 'UPDATE orders O
                JOIN (
                    SELECT order_id, IFNULL(SUM(total), 0) AS new_total
                    FROM order_product
                    WHERE order_product_id IS NULL
                    GROUP BY order_id
                ) AS subquery ON O.id = subquery.order_id
                SET O.price = IFNULL(subquery.new_total, 0)
                WHERE O.id = :order_id;
                ';
        $connection = $this->manager->getConnection();
        $statement = $connection->prepare($sql);
        $statement->bindValue(':order_id', $order->getId(), Type::getType('integer'));
        $statement->executeStatement();

        return $order;
    }

    public function calculateGroupProductPrice(Order $order)
    {
        $sql = 'UPDATE order_product OPO
                JOIN (
                        SELECT SUM(calculated_price) AS calculated_price,order_product_id FROM (	SELECT 
                                PG.product_group,
                                P.product,
                                PG.price_calculation,
                                OP.order_product_id,
                                (CASE 
                                    WHEN PG.price_calculation = "biggest" THEN MAX(PGP.price)
                                    WHEN PG.price_calculation = "sum" THEN SUM(PGP.price)
                                    WHEN PG.price_calculation = "average" THEN AVG(PGP.price) 
                                    WHEN PG.price_calculation = "free" THEN 0
                                    ELSE NULL
                                END)  AS calculated_price
                                
                            FROM order_product OP
                            INNER JOIN product_group PG ON OP.product_group_id = PG.id
                            INNER JOIN product_group_product PGP ON PGP.product_group_id = OP.product_group_id AND PGP.product_child_id = OP.product_id
                            INNER JOIN product P ON P.id = OP.product_id
                            WHERE OP.parent_product_id IS NOT NULL AND OP.order_id = :order_id
                            GROUP BY OP.order_product_id,PG.id
                        ) AS SBG GROUP BY SBG.order_product_id
                        
                ) AS subquery ON OPO.id = subquery.order_product_id
                SET OPO.price = subquery.calculated_price,OPO.total = (subquery.calculated_price * OPO.quantity)
                ';
        $connection = $this->manager->getConnection();
        $statement = $connection->prepare($sql);
        $statement->bindValue(':order_id', $order->getId(), Type::getType('integer'));
        $statement->executeStatement();

        return $order;
    }

    public function createOrder(People $receiver, People $payer)
    {
        $status = $this->manager->getRepository(Status::class)->findOneBy([
            'status' => 'waiting payment',
            'context' => 'order'
        ]);

        $order = new Order();
        $order->setProvider($receiver);
        $order->setClient($payer);
        $order->setPayer($payer);
        $order->setOrderType('sale');
        $order->setStatus($status);
        $order->setApp('Asaas');

        $this->manager->persist($order);
        $this->manager->flush();
        return $order;
    }

    public function securityFilter(QueryBuilder $queryBuilder, $resourceClass = null, $applyTo = null, $rootAlias = null): void
    {
        $companies   = $this->peopleService->getMyCompanies();

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
