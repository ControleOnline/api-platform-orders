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
use Symfony\Component\HttpFoundation\Response;

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

            $data = json_decode($request->getContent(), true) ?? [];
            $deviceId = trim((string) ($data['device'] ?? ''));
            if ($deviceId === '') {
                return new JsonResponse(['error' => 'Device not informed'], 400);
            }

            $device = $this->entityManager->getRepository(Device::class)->findOneBy([
                'device' => $deviceId
            ]);

            if (!$device) {
                return new JsonResponse(['error' => 'Device not found'], 404);
            }

            $queueIds = $this->normalizeIds($data['queueIds'] ?? []);
            $orderProductQueueIds = $this->normalizeIds(
                $data['orderProductQueueIds'] ?? []
            );

            if (!empty($orderProductQueueIds)) {
                $printData = $this->print->generateQueueEntryPrintData(
                    $order,
                    $device,
                    $orderProductQueueIds
                );
            } elseif (!empty($queueIds)) {
                $printData = $this->print->generateQueuePrintData(
                    $order,
                    $device,
                    $queueIds
                );
            } else {
                $printData = $this->print->generatePrintData($order, $device);
            }

            if (!$printData) {
                return new JsonResponse(
                    ['error' => 'Nothing to print for the selected queue data'],
                    422
                );
            }

            return new JsonResponse($this->hydratorService->item(Spool::class, $printData->getId(), "spool_item:read"), Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }

    private function normalizeIds(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } elseif (trim($value) !== '') {
                $value = [$value];
            } else {
                $value = [];
            }
        } elseif (!is_array($value)) {
            $value = $value === null ? [] : [$value];
        }

        return array_values(array_filter(array_map(
            static fn($item) => trim((string) $item),
            $value
        )));
    }
}
