<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\ProductGroupProduct;
use Exception;

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
            $text = "      PEDIDO #" . $order->getId() . "\n";
            $text .= "      Data: " . $order->getOrderDate()->format('d/m/Y H:i') . "\n";
            $client = $order->getClient();
            $text .= "      Cliente: " . ($client !== null ? $client->getName() : 'Não informado') . "\n";
            $text .= "      Total: R$ " . number_format($order->getPrice(), 2, ',', '.') . "\n";
            $text .= "      ------------------------\n";

            $queues = [];
            foreach ($order->getOrderProducts() as $orderProduct) {
                $queueEntries = $orderProduct->getOrderProductQueues();

                if ($queueEntries->isEmpty()) {
                    if (!isset($queues['Sem fila definida'])) {
                        $queues['Sem fila definida'] = [];
                    }
                    $queues['Sem fila definida'][] = $orderProduct;
                } else {
                    foreach ($queueEntries as $queueEntry) {
                        $queue = $queueEntry->getQueue();
                        $queueName = $queue ? $queue->getQueue() : 'Sem fila definida';


                        if (!isset($queues[$queueName])) {
                            $queues[$queueName] = [];
                        }
                        $queues[$queueName][] = $orderProduct;
                    }
                }
            }

            foreach ($queues as $queueName => $products) {
                $text .= "      " . strtoupper($queueName) . ":\n";
                foreach ($products as $orderProduct) {
                    $product = $orderProduct->getProduct();
                    $unit = $product->getProductUnit()->getProductUnit();
                    $quantity = $orderProduct->getQuantity();

                    $text .= "      - " . $product->getProduct() . " (" . $quantity . " " . $unit . ")\n";
                    $text .= "      ..............";
                    $text .= "        R$ " . number_format($product->getPrice() * $quantity, 2, ',', '.') . "\n";

                    if ($product->getType() === 'custom') {
                        $text .= "        Personalizações:\n";
                        $productGroupProducts = $this->entityManager->getRepository(ProductGroupProduct::class)
                            ->findBy(['product' => $product->getId()]);

                        foreach ($productGroupProducts as $pgp) {
                            $childProduct = $pgp->getProductChild();
                            if ($childProduct) {
                                $text .= "          - " . $childProduct->getProduct() . " (" . $pgp->getQuantity() . " " . $childProduct->getProductUnit()->getProductUnit() . ")\n";
                            }
                        }
                    }
                }
                $text .= "      \n";
            }

            $text .= "      ------------------------\n";



            return   [
                "operation" => "PRINT_TEXT",
                "styles" => [[]],
                "value" => [$text]
            ];
        }

        throw new Exception("Unsupported print type", 1);
    }
}
