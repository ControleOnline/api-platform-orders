<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGenerator;
use Doctrine\ORM\EntityManagerInterface;
use ControleOnline\Entity\Order;

class PrintOrderAction
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function __invoke(Request $request, int $id): JsonResponse
    {
        // Busca o pedido pelo ID
        $order = $this->entityManager->getRepository(Order::class)->find($id);
        if (!$order) {
            return new JsonResponse(['error' => 'Order not found'], 404);
        }

        // Pega os parâmetros da requisição (print-type e device-type)
        $data = json_decode($request->getContent(), true);
        $printType = $data['print-type'] ?? 'pos';
        $deviceType = $data['device-type'] ?? 'cielo';

        // Lógica para decidir o que retornar (texto ou imagem)
        $printData = $this->generatePrintData($order, $printType, $deviceType);

        // Retorna os dados a serem impressos
        return new JsonResponse($printData);
    }

    private function generatePrintData(Order $order, string $printType, string $deviceType): array|string
    {
        if ($deviceType !== 'cielo') {
            return ['error' => 'Unsupported device type'];
        }

        if ($printType === 'pos') {
            // Exemplo: retorna um texto simples para impressão no POS
            $text = "Order ID: " . $order->getId() . "\n";
            $text .= "Client: " . $order->getClient()->getName() . "\n";
            $text .= "Price: " . number_format($order->getPrice(), 2, ',', '.') . "\n";
            $text .= "Date: " . $order->getOrderDate()->format('d/m/Y H:i:s') . "\n";

            foreach ($order->getOrderProducts() as $product) {
                $text .= "- " . $product->getProduct()->getName() . " x" . $product->getQuantity() . "\n";
            }

            return $text;
        }

        // Caso queira suportar imagem no futuro
        // Exemplo fictício de imagem em base64
        /*
        if ($printType === 'image') {
            $imagePath = $this->generateImage($order); // Implementar lógica para gerar imagem
            $imageContent = file_get_contents($imagePath);
            return base64_encode($imageContent);
        }
        */

        return ['error' => 'Unsupported print type'];
    }
}