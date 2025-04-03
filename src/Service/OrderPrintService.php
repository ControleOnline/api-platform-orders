<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\ProductGroupProduct;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

class OrderPrintService
{
    private $noQueue = 'Sem fila definida';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PrintService $printService
    ) {}


    private function getQueues(Order $order)
    {
        $queues = [];
        foreach ($order->getOrderProducts() as $orderProduct) {
            $queueEntries = $orderProduct->getOrderProductQueues();
            if ($queueEntries->isEmpty()) {
                if (!isset($queues[$this->noQueue])) {
                    $queues[$this->noQueue] = [];
                }
                $queues[$this->noQueue][] = $orderProduct;
            } else {
                foreach ($queueEntries as $queueEntry) {
                    $queue = $queueEntry->getQueue();
                    $queueName = $queue ? $queue->getQueue() : $this->noQueue;
                    if (!isset($queues[$queueName])) {
                        $queues[$queueName] = [];
                    }
                    $queues[$queueName][] = $orderProduct;
                }
            }
        }
        return $queues;
    }

    private function printProduct($orderProduct, $indent = "- ")
    {
        $product = $orderProduct->getProduct();

        $quantity = $orderProduct->getQuantity();
        $this->printService->addLine(
            $indent . $quantity . ' X ' . $product->getProduct(),
            " R$ " . number_format($orderProduct->getTotal(), 2, ',', '.'),
            '.'
        );
    }

    private function printChildren($orderProducts)
    {
        $groupedChildren = [];
        foreach ($orderProducts as $orderProductChild) {
            $productGroup = $orderProductChild->getProductGroup();
            $groupName = $productGroup ? $productGroup->getProductGroup() : 'Sem Grupo';
            if (!isset($groupedChildren[$groupName])) {
                $groupedChildren[$groupName] = [];
            }
            $groupedChildren[$groupName][] = $orderProductChild;
        }

        foreach ($groupedChildren as $groupName => $orderProductChildren) {
            $this->printService->addLine(strtoupper($groupName) . ":");
            foreach ($orderProductChildren as $orderProductChild) {
                $product = $orderProductChild->getProduct();
                $this->printService->addLine("  - " . $product->getProduct());
            }
        }
        $this->printService->addLine('', '', '-');
    }

    private function printQueueProducts($orderProducts)
    {
        $parentOrderProducts = array_filter($orderProducts, fn($orderProduct) => $orderProduct->getOrderProduct() === null);


        foreach ($parentOrderProducts as $parentOrderProduct) {
            $this->printProduct($parentOrderProduct);

            $childs = $parentOrderProduct->getOrderProductComponents();
            if (!empty($childs))
                $this->printChildren($childs);

            $this->printService->addLine('', '', '-');
        }
    }

    private function printQueues($queues)
    {
        foreach ($queues as $queueName => $orderProducts) {
            $parentOrderProducts = array_filter($orderProducts, fn($orderProduct) => $orderProduct->getOrderProduct() === null);
            if (!empty($parentOrderProducts)) {
                $this->printService->addLine(strtoupper($queueName) . ":");
                $this->printQueueProducts($orderProducts);
                $this->printService->addLine('', '', ' ');
            }
        }
    }

    public function generatePrintData(Order $order, string $printType, string $deviceType)
    {

        $this->printService->addLine("PEDIDO #" . $order->getId());
        $this->printService->addLine($order->getOrderDate()->format('d/m/Y H:i'));
        $client = $order->getClient();
        $this->printService->addLine(($client !== null ? $client->getName() : 'Não informado'));
        $this->printService->addLine("R$ " . number_format($order->getPrice(), 2, ',', '.'));
        $this->printService->addLine("", "", "-");
        $queues = $this->getQueues($order);

        $this->printQueues($queues);
        $this->printService->addLine("", "", "-");
        return $this->printService->generatePrintData($printType, $deviceType);
    }
}
