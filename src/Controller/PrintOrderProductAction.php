<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Spool;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderPrintService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PrintOrderProductAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderPrintService $print,
        private HydratorService $hydratorService
    ) {}

    public function __invoke(Request $request, int $id): JsonResponse
    {
        try {
            $orderProduct = $this->entityManager
                ->getRepository(OrderProduct::class)
                ->find($id);

            if (!$orderProduct instanceof OrderProduct) {
                return new JsonResponse(['error' => 'Order product not found'], 404);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $deviceId = trim((string) ($data['device'] ?? ''));
            if ($deviceId === '') {
                return new JsonResponse(['error' => 'Device not informed'], 400);
            }

            $device = $this->entityManager->getRepository(Device::class)->findOneBy([
                'device' => $deviceId
            ]);

            if (!$device instanceof Device) {
                return new JsonResponse(['error' => 'Device not found'], 404);
            }

            $orderProductQueueIds = $this->normalizeIds(
                $data['orderProductQueueIds'] ?? []
            );

            $printData = $this->print->generateOrderProductPrintData(
                $orderProduct,
                $device,
                $orderProductQueueIds
            );

            if (!$printData instanceof Spool) {
                return new JsonResponse(
                    ['error' => 'Nothing to print for the selected product'],
                    422
                );
            }

            return new JsonResponse(
                $this->hydratorService->item(
                    Spool::class,
                    $printData->getId(),
                    "spool_item:read"
                ),
                Response::HTTP_OK
            );
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
