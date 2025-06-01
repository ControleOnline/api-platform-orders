<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\Spool;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

class OrderPrintService
{
    private $noQueue = 'Sem fila definida';

    public function __construct(
        private EntityManagerInterface $manager,
        private PrintService $printService,
        private ConfigService $configService,
        private DeviceService $deviceService,
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

    public  function printOrder(Order $order, ?array $devices = [])
    {
        if (empty($devices))
            $devices = $this->configService->getConfig($order->getProvider(), 'order-print-devices', true);

        if ($devices)
            $devices = $this->deviceService->findDevices($devices);

        foreach ($devices as $device)
            $this->generatePrintData($order, $device);
    }

    private function printProduct($orderProduct, $indent = "- ", $printForm = false)
    {
        $product = $orderProduct->getProduct();
        $quantity = $printForm ? 1 : $orderProduct->getQuantity();
        $total = $printForm ? $orderProduct->getPrice() : $orderProduct->getTotal();

        if ($printForm) $this->printService->addLine('', '', ' ');

        $this->printService->addLine(
            $indent . $quantity . ' X ' . $product->getProduct(),
            " R$ " . number_format($total, 2, ',', '.'),
            '.'
        );

        if ($printForm) $this->printService->addLine('', '', ' ');
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

    private function printQueueProducts($orderProducts, $printForm)
    {
        $parentOrderProducts = array_filter($orderProducts, fn($orderProduct) => $orderProduct->getOrderProduct() === null);

        foreach ($parentOrderProducts as $parentOrderProduct) {
            $quantity = $printForm ? $parentOrderProduct->getQuantity() : 1;
            for ($i = 0; $i < $quantity; $i++) {
                $this->printProduct($parentOrderProduct,  "- ", $printForm);

                $childs = $parentOrderProduct->getOrderProductComponents();
                if (!empty($childs))
                    $this->printChildren($childs);

                $this->printService->addLine('', '', '-');
            }
        }
    }

    private function printQueues($queues, $printForm)
    {
        foreach ($queues as $queueName => $orderProducts) {
            $parentOrderProducts = array_filter($orderProducts, fn($orderProduct) => $orderProduct->getOrderProduct() === null);
            if (!empty($parentOrderProducts)) {
                if (!$printForm) $this->printService->addLine(strtoupper($queueName) . ":");
                $this->printQueueProducts($orderProducts, $printForm);
                if ($printForm) $this->printService->addLine('', '', ' ');
                $this->printService->addLine('', '', ' ');
            }
        }
    }

    public function generatePrintData(Order $order, Device $device, ?array $aditionalData = []): Spool
    {
        $device_configs = $this->manager->getRepository(DeviceConfig::class)->findOneBy([
            'device' => $device->getId()
        ]);

        $printForm = ($device_configs->getConfigs(true)['print-mode'] ?? 'order') == 'form';

        $this->printService->addLine("PEDIDO #" . $order->getId());
        $this->printService->addLine($order->getOrderDate()->format('d/m/Y H:i'));

        if (!$printForm) {
            $client = $order->getClient();
            $this->printService->addLine(($client !== null ? $client->getName() : 'Não informado'));
            $this->printService->addLine("R$ " . number_format($order->getPrice(), 2, ',', '.'));
        }

        $this->printService->addLine("", "", "-");
        $queues = $this->getQueues($order);

        $this->printQueues($queues, $printForm);
        $this->printService->addLine("", "", "-");

        return $this->printService->generatePrintData($device, $order->getProvider(), $aditionalData);
    }
}
