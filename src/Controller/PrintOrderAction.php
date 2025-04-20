<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Device;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\Spool;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderPrintService;
use Exception;

class PrintOrderAction
{


    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderPrintService $print,
        private HydratorService $hydratorService

    ) {}

    public function __invoke(Request $request, int $id): JsonResponse
    {
        try {
            $order = $this->entityManager->getRepository(Order::class)->find($id);
            if (!$order) {
                return new JsonResponse(['error' => 'Order not found'], 404);
            }

            $data = json_decode($request->getContent(), true);
            $device = $this->entityManager->getRepository(Device::class)->findOneBy([
                'device' => $data['device']
            ]);

            $printData = $this->print->generatePrintData($order, $device);
            return new JsonResponse($this->hydratorService->item(Spool::class, $printData->getId(), "spool_item:read"), Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
