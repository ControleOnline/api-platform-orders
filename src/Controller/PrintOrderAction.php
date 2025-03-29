<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\OrderProductQueue;

class PrintOrderAction
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function __invoke(Request $request, int $id): JsonResponse
    {
        $order = $this->entityManager->getRepository(Order::class)->find($id);
        if (!$order) {
            return new JsonResponse(['error' => 'Order not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $printType = $data['print-type'] ?? 'pos';
        $deviceType = $data['device-type'] ?? 'cielo';

        $printData = $this->generatePrintData($order, $printType, $deviceType);

        return new JsonResponse($printData);
    }

    private function generatePrintData(Order $order, string $printType, string $deviceType)
    {
        if ($deviceType !== 'cielo') {
            return ['error' => 'Unsupported device type'];
        }

        if ($printType === 'pos') {
            $text = "PEDIDO #" . $order->getId() . "\n";
            $text .= "Data: " . $order->getOrderDate()->format('d/m/Y H:i') . "\n";
            $client = $order->getClient();
            $text .= "Cliente: " . ($client !== null ? $client->getName() : 'Não informado') . "\n";
            $text .= "Total: R$ " . number_format($order->getPrice(), 2, ',', '.') . "\n";
            $text .= "------------------------\n";

            // Agrupar produtos por fila usando OrderProductQueue
            $queues = [];
            foreach ($order->getOrderProducts() as $orderProduct) {
                $queueEntries = $orderProduct->getOrderProductQueues();

                // Se não houver filas associadas, coloca em "Sem fila definida"
                if ($queueEntries->isEmpty()) {
                    if (!isset($queues['Sem fila definida'])) {
                        $queues['Sem fila definida'] = [];
                    }
                    $queues['Sem fila definida'][] = $orderProduct;
                } else {
                    // Adiciona o produto em todas as filas associadas
                    foreach ($queueEntries as $queueEntry) {
                        $queue = $queueEntry->getQueue();
                        $queueName = $queue ? $queue->getQueue() : 'Sem fila definida';

                        // Log para depuração
                        error_log("Produto: " . $orderProduct->getProduct()->getProduct() . " | Queue ID: " . ($queue ? $queue->getId() : 'NULL') . " | Queue Name: " . $queueName);

                        if (!isset($queues[$queueName])) {
                            $queues[$queueName] = [];
                        }
                        $queues[$queueName][] = $orderProduct;
                    }
                }
            }

            // Exibir produtos organizados por fila
            foreach ($queues as $queueName => $products) {
                $text .= strtoupper($queueName) . ":\n";
                foreach ($products as $orderProduct) {
                    $product = $orderProduct->getProduct();
                    $unit = $product->getProductUnit()->getProductUnit();
                    $quantity = $orderProduct->getQuantity();

                    $text .= "$quantity" . " " . $unit . " X " . $product->getProduct() . ")\n";
                    $text .= "..............";
                    $text .= " R$ " . number_format($product->getPrice() * $quantity, 2, ',', '.') . "\n";

                    // Verifica se o produto é customizado
                    if ($product->getType() === 'custom') {
                        $text .= "  Personalizações:\n";
                        $productGroupProducts = $this->entityManager->getRepository(ProductGroupProduct::class)
                            ->findBy(['product' => $product->getId()]);

                        foreach ($productGroupProducts as $pgp) {
                            $childProduct = $pgp->getProductChild();
                            if ($childProduct) {
                                $text .= "    - " . $childProduct->getProduct() . " (" . $pgp->getQuantity() . " " . $childProduct->getProductUnit()->getProductUnit() . ")\n";
                            }
                        }
                    }
                }
                $text .= "\n";
            }

            $text .= "------------------------\n";

            return $text;
        }

        return ['error' => 'Unsupported print type'];
    }
}
