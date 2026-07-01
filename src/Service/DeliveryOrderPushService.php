<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Event\EntityChangedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

class DeliveryOrderPushService implements EventSubscriberInterface
{
    private const ROUTE_NAME = 'OrderDetails';
    private const AWAITING_ACCEPTANCE_MARKERS = [
        'aguardando aceite',
        'awaiting acceptance',
        'waiting acceptance',
        'pending acceptance',
        'acceptance pending',
        'requested',
        'pending',
        'pendente',
    ];

    public function __construct(
        private EntityManagerInterface $manager,
        private FirebaseCloudMessagingService $firebaseCloudMessagingService,
        private LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            EntityChangedEvent::class => 'onEntityChanged',
        ];
    }

    public function onEntityChanged(EntityChangedEvent $event): void
    {
        if (!in_array($event->getPhase(), ['postPersist', 'postUpdate'], true)) {
            return;
        }

        $order = $event->getEntity();
        if (!$order instanceof Order || !$this->shouldNotify($event, $order)) {
            return;
        }

        try {
            $this->sendAwaitingAcceptanceNotification($order);
        } catch (Throwable $throwable) {
            $this->logger->warning('Unable to send delivery awaiting acceptance push notification.', [
                'orderId' => $order->getId(),
                'deliveryPeopleId' => $order->getDeliveryPeople()?->getId(),
                'exceptionClass' => $throwable::class,
                'exceptionMessage' => $throwable->getMessage(),
                'exception' => $throwable,
            ]);
        }
    }

    public function sendAwaitingAcceptanceNotification(Order $order): int
    {
        if (!$this->isDeliveryAwaitingAcceptance($order)) {
            return 0;
        }

        $orderId = (int) ($order->getId() ?? 0);
        $deliveryPeople = $order->getDeliveryPeople();
        if ($orderId <= 0 || !$deliveryPeople instanceof People || !$deliveryPeople->getId()) {
            return 0;
        }

        $tokens = $this->resolveDeliveryDeviceTokens($deliveryPeople);
        if ($tokens === []) {
            return 0;
        }

        $providerLabel = trim((string) (
            $order->getProvider()?->getAlias()
            ?: $order->getProvider()?->getName()
            ?: $order->getProvider()?->getId()
        ));

        $title = sprintf('Pedido #%s aguardando aceite', $orderId);
        $body = $providerLabel !== ''
            ? sprintf('%s: aceite a corrida para assumir a entrega.', $providerLabel)
            : 'Aceite a corrida para assumir a entrega.';
        $data = [
            'event' => 'delivery.awaiting_acceptance',
            'route' => self::ROUTE_NAME,
            'routeName' => self::ROUTE_NAME,
            'screen' => self::ROUTE_NAME,
            'orderId' => (string) $orderId,
            'order' => (string) $orderId,
            'companyId' => (string) ($order->getProvider()?->getId() ?? ''),
            'deliveryPeopleId' => (string) $deliveryPeople->getId(),
        ];

        $sentCount = 0;
        foreach ($tokens as $token) {
            try {
                $this->firebaseCloudMessagingService->sendNotificationToToken(
                    $token,
                    $title,
                    $body,
                    $data
                );
                $sentCount++;
            } catch (Throwable $throwable) {
                $this->logger->warning('Unable to send delivery awaiting acceptance notification.', [
                    'orderId' => $orderId,
                    'deliveryPeopleId' => $deliveryPeople->getId(),
                    'tokenHash' => hash('sha256', $token),
                    'exceptionClass' => $throwable::class,
                    'exceptionMessage' => $throwable->getMessage(),
                    'exception' => $throwable,
                ]);
            }
        }

        return $sentCount;
    }

    private function shouldNotify(EntityChangedEvent $event, Order $order): bool
    {
        if (!$this->isDeliveryAwaitingAcceptance($order)) {
            return false;
        }

        if ($event->getPhase() === 'postPersist') {
            return true;
        }

        $oldEntity = $event->getOldEntity();
        if (!$oldEntity instanceof Order) {
            return true;
        }

        $currentState = $this->resolveNotificationState($order);
        $oldState = $this->resolveNotificationState($oldEntity);

        if (!$oldState['eligible']) {
            return true;
        }

        return $oldState['deliveryPeopleId'] !== $currentState['deliveryPeopleId'];
    }

    private function resolveNotificationState(Order $order): array
    {
        $deliveryPeople = $order->getDeliveryPeople();
        $deliveryPeopleId = $deliveryPeople instanceof People ? (int) $deliveryPeople->getId() : 0;
        $eligible = $this->isDeliveryOrder($order)
            && $deliveryPeopleId > 0
            && $this->containsAwaitingAcceptanceStatus($this->collectStatusTokens($order));

        return [
            'eligible' => $eligible,
            'deliveryPeopleId' => $deliveryPeopleId,
        ];
    }

    private function isDeliveryAwaitingAcceptance(Order $order): bool
    {
        return $this->resolveNotificationState($order)['eligible'];
    }

    private function isDeliveryOrder(Order $order): bool
    {
        return $this->normalizeText($order->getOrderType()) === Order::ORDER_TYPE_DELIVERY;
    }

    private function containsAwaitingAcceptanceStatus(array $statusTokens): bool
    {
        foreach ($statusTokens as $statusToken) {
            foreach (self::AWAITING_ACCEPTANCE_MARKERS as $marker) {
                if (str_contains($statusToken, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function collectStatusTokens(Order $order): array
    {
        $tokens = [];

        $status = $order->getStatus();
        if ($status instanceof \ControleOnline\Entity\Status) {
            $tokens[] = $this->normalizeText($status->getStatus());
            $tokens[] = $this->normalizeText($status->getRealStatus());
        }

        $otherInformations = $order->getOtherInformations(true);
        if (!is_object($otherInformations)) {
            return array_values(array_filter(array_unique($tokens)));
        }

        foreach (['quote_state', 'status', 'delivery_status'] as $fieldName) {
            $tokens[] = $this->normalizeText($otherInformations->{$fieldName} ?? null);
        }

        foreach (['logistics', 'delivery'] as $sectionName) {
            $section = $otherInformations->{$sectionName} ?? null;
            if (!is_object($section)) {
                continue;
            }

            foreach (['status', 'quote_state', 'real_status'] as $fieldName) {
                $tokens[] = $this->normalizeText($section->{$fieldName} ?? null);
            }
        }

        return array_values(array_filter(array_unique($tokens)));
    }

    private function resolveDeliveryDeviceTokens(People $deliveryPeople): array
    {
        $deviceConfigs = $this->manager->getRepository(DeviceConfig::class)->findBy([
            'people' => $deliveryPeople,
        ]);

        $tokens = [];
        foreach ($deviceConfigs as $deviceConfig) {
            if (!$deviceConfig instanceof DeviceConfig) {
                continue;
            }

            $token = $this->extractDeliveryAndroidToken($deviceConfig->getDevice()->getMetadata());
            if ($token === '') {
                continue;
            }

            $tokens[$token] = $token;
        }

        return array_values($tokens);
    }

    private function extractDeliveryAndroidToken(array $metadata): string
    {
        $pushTokens = $metadata['pushTokens'] ?? [];
        if (!is_array($pushTokens)) {
            return '';
        }

        $deliveryTokens = $pushTokens['delivery'] ?? [];
        if (!is_array($deliveryTokens)) {
            return '';
        }

        $androidTokens = $deliveryTokens['android'] ?? [];
        if (!is_array($androidTokens)) {
            return '';
        }

        return $this->normalizeText($androidTokens['deviceToken'] ?? '');
    }

    private function normalizeText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
