<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class OrderConferencePrintService
{
    private const OTHER_INFORMATION_KEY = 'conference_print';
    private const DEFAULT_SOURCE = 'display-auto';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderPrintService $orderPrintService,
    ) {}

    private function normalizeText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function normalizeOptionalNumericId(mixed $value): ?int
    {
        $normalized = (int) preg_replace('/\D+/', '', (string) ($value ?? ''));

        return $normalized > 0 ? $normalized : null;
    }

    private function normalizeOtherInformations(Order $order): array
    {
        $otherInformations = $order->getOtherInformations(true);
        $normalized = json_decode(json_encode($otherInformations), true);

        return is_array($normalized) ? $normalized : [];
    }

    private function resolveConferencePrintState(Order $order): array
    {
        $otherInformations = $this->normalizeOtherInformations($order);
        $state = $otherInformations[self::OTHER_INFORMATION_KEY] ?? null;

        return is_array($state) ? $state : [];
    }

    private function hasConferencePrintMark(Order $order): bool
    {
        $state = $this->resolveConferencePrintState($order);

        return !empty($state['printed']) || $this->normalizeText($state['printed_at'] ?? null) !== '';
    }

    private function buildConferencePrintState(
        Order $order,
        array $payload,
        int $spoolId
    ): array {
        $provider = $order->getProvider();

        return [
            'printed' => true,
            'printed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'source' => $this->normalizeText($payload['source'] ?? null) ?: self::DEFAULT_SOURCE,
            'display_id' => $this->normalizeOptionalNumericId(
                $payload['displayId'] ?? $payload['display_id'] ?? null
            ),
            'device' => $this->normalizeText($payload['device'] ?? null),
            'device_type' => $this->normalizeText(
                $payload['type'] ?? $payload['deviceType'] ?? null
            ),
            'people' => $this->normalizeOptionalNumericId(
                $payload['people'] ?? $provider?->getId()
            ),
            'spool_id' => $spoolId,
            'order_id' => (int) $order->getId(),
        ];
    }

    private function persistConferencePrintState(Order $order, array $state): void
    {
        $otherInformations = $this->normalizeOtherInformations($order);
        $otherInformations[self::OTHER_INFORMATION_KEY] = $state;
        $order->setOtherInformations($otherInformations);
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }

    public function autoPrintIfNeeded(Order $order, array $payload = []): array
    {
        $orderId = (int) ($order->getId() ?? 0);
        if ($orderId <= 0) {
            return [
                'printed' => false,
                'alreadyPrinted' => false,
                'spoolId' => null,
                'printedAt' => null,
                'orderId' => null,
            ];
        }

        return $this->entityManager
            ->getConnection()
            ->transactional(function () use ($orderId, $payload): array {
                $managedOrder = $this->entityManager->find(
                    Order::class,
                    $orderId,
                    LockMode::PESSIMISTIC_WRITE
                );

                if (!$managedOrder instanceof Order) {
                    return [
                        'printed' => false,
                        'alreadyPrinted' => false,
                        'spoolId' => null,
                        'printedAt' => null,
                        'orderId' => null,
                    ];
                }

                if ($this->hasConferencePrintMark($managedOrder)) {
                    $state = $this->resolveConferencePrintState($managedOrder);

                    return [
                        'printed' => false,
                        'alreadyPrinted' => true,
                        'spoolId' => $this->normalizeOptionalNumericId($state['spool_id'] ?? null),
                        'printedAt' => $this->normalizeText($state['printed_at'] ?? null) ?: null,
                        'orderId' => (int) $managedOrder->getId(),
                    ];
                }

                $printData = $this->orderPrintService->generatePrintDataFromPayload(
                    $managedOrder,
                    $payload
                );

                if (!$printData) {
                    return [
                        'printed' => false,
                        'alreadyPrinted' => false,
                        'spoolId' => null,
                        'printedAt' => null,
                        'orderId' => (int) $managedOrder->getId(),
                    ];
                }

                $state = $this->buildConferencePrintState(
                    $managedOrder,
                    $payload,
                    (int) $printData->getId()
                );
                $this->persistConferencePrintState($managedOrder, $state);

                return [
                    'printed' => true,
                    'alreadyPrinted' => false,
                    'spoolId' => (int) $printData->getId(),
                    'printedAt' => $state['printed_at'],
                    'orderId' => (int) $managedOrder->getId(),
                ];
            });
    }
}
