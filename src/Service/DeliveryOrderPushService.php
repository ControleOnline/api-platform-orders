<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Event\EntityChangedEvent;
use ControleOnline\Service\Client\WebsocketClient;
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
        private WebsocketClient $websocketClient,
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
        if (!$order instanceof Order) {
            return;
        }

        try {
            $this->sendDeliveryLifecycleNotification($event, $order);
        } catch (Throwable $throwable) {
            $this->logger->warning('Unable to send delivery lifecycle push notification.', [
                'orderId' => $order->getId(),
                'deliveryPeopleId' => $order->getDeliveryPeople()?->getId(),
                'exceptionClass' => $throwable::class,
                'exceptionMessage' => $throwable->getMessage(),
                'exception' => $throwable,
            ]);
        }
    }

    public function sendDeliveryLifecycleNotification(EntityChangedEvent $event, Order $order): int
    {
        if (!$this->isDeliveryOrder($order)) {
            return 0;
        }

        $deliveryPeople = $order->getDeliveryPeople();
        if (!$deliveryPeople instanceof People || !$deliveryPeople->getId()) {
            return 0;
        }

        $currentState = $this->resolveLifecycleState($order);
        if ($currentState === '') {
            return 0;
        }

        $oldEntity = $event->getOldEntity();
        if ($event->getPhase() === 'postUpdate' && $oldEntity instanceof Order) {
            $oldState = $this->resolveLifecycleState($oldEntity);

            if ($oldState === $currentState) {
                return 0;
            }
        }

        $message = $this->buildLifecycleMessage($order, $currentState);
        if ($message === null) {
            return 0;
        }

        return $this->broadcastLifecycleMessage($order, $deliveryPeople, $message);
    }

    private function buildLifecycleMessage(Order $order, string $state): ?array
    {
        $orderId = (int) ($order->getId() ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        $providerLabel = trim((string) (
            $order->getProvider()?->getAlias()
            ?: $order->getProvider()?->getName()
            ?: $order->getProvider()?->getId()
        ));

        $event = match ($state) {
            'awaiting_acceptance' => 'delivery.awaiting_acceptance',
            'accepted' => 'delivery.accepted',
            'in_route' => 'delivery.in_route',
            'delivered' => 'delivery.delivered',
            'rejected' => 'delivery.rejected',
            default => '',
        };

        if ($event === '') {
            return null;
        }

        $title = match ($state) {
            'awaiting_acceptance' => sprintf('Pedido #%s aguardando aceite', $orderId),
            'accepted' => sprintf('Pedido #%s aceito', $orderId),
            'in_route' => sprintf('Pedido #%s em rota', $orderId),
            'delivered' => sprintf('Pedido #%s entregue', $orderId),
            'rejected' => sprintf('Pedido #%s recusado', $orderId),
            default => sprintf('Pedido #%s atualizado', $orderId),
        };

        $body = match ($state) {
            'awaiting_acceptance' => $providerLabel !== ''
                ? sprintf('%s: aceite a corrida para assumir a entrega.', $providerLabel)
                : 'Aceite a corrida para assumir a entrega.',
            'accepted' => 'Corrida confirmada. Fique na tela para acompanhar a entrega.',
            'in_route' => 'Corrida em andamento. Marque cada parada como entregue para seguir.',
            'delivered' => 'Parada concluida. O app vai liberar a proxima entrega.',
            'rejected' => 'Corrida recusada. A entrega voltara para a fila.',
            default => 'Status da entrega atualizado.',
        };

        $data = [
            'event' => $event,
            'route' => self::ROUTE_NAME,
            'routeName' => self::ROUTE_NAME,
            'screen' => self::ROUTE_NAME,
            'orderId' => (string) $orderId,
            'order' => (string) $orderId,
            'companyId' => (string) ($order->getProvider()?->getId() ?? ''),
            'deliveryPeopleId' => (string) ($order->getDeliveryPeople()?->getId() ?? ''),
            'orderType' => Order::ORDER_TYPE_DELIVERY,
            'deliveryState' => $state,
            'status' => $this->normalizeText($order->getStatus()?->getStatus()),
            'realStatus' => $this->normalizeText($order->getStatus()?->getRealStatus()),
        ];

        return [
            'event' => $event,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ];
    }

    private function broadcastLifecycleMessage(Order $order, People $deliveryPeople, array $message): int
    {
        $deviceConfigs = $this->resolveDeliveryDeviceConfigs($deliveryPeople);
        if ($deviceConfigs === []) {
            return 0;
        }

        $sentCount = 0;
        foreach ($deviceConfigs as $deviceConfig) {
            if (!$deviceConfig instanceof DeviceConfig) {
                continue;
            }

            $device = $deviceConfig->getDevice();
            if (!$device) {
                continue;
            }

            $token = $this->extractDeliveryAndroidToken($device->getMetadata());
            if ($token !== '') {
                try {
                    $this->firebaseCloudMessagingService->sendNotificationToToken(
                        $token,
                        $message['title'],
                        $message['body'],
                        $message['data']
                    );
                    $sentCount++;
                } catch (Throwable $throwable) {
                    $this->logger->warning('Unable to send delivery lifecycle notification.', [
                        'orderId' => $order->getId(),
                        'deliveryPeopleId' => $deliveryPeople->getId(),
                        'tokenHash' => hash('sha256', $token),
                        'exceptionClass' => $throwable::class,
                        'exceptionMessage' => $throwable->getMessage(),
                        'exception' => $throwable,
                    ]);
                }
            }

            try {
                $payload = json_encode([$message['data']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($payload !== false) {
                    $this->websocketClient->push($device, $payload);
                }
            } catch (Throwable $throwable) {
                $this->logger->warning('Unable to send delivery lifecycle websocket event.', [
                    'orderId' => $order->getId(),
                    'deliveryPeopleId' => $deliveryPeople->getId(),
                    'exceptionClass' => $throwable::class,
                    'exceptionMessage' => $throwable->getMessage(),
                    'exception' => $throwable,
                ]);
            }
        }

        return $sentCount;
    }

    private function resolveDeliveryDeviceConfigs(People $deliveryPeople): array
    {
        return $this->manager->getRepository(DeviceConfig::class)->findBy([
            'people' => $deliveryPeople,
        ]);
    }

    private function resolveLifecycleState(Order $order): string
    {
        $statusTokens = $this->collectStatusTokens($order);

        if ($this->containsAwaitingAcceptanceStatus($statusTokens)) {
            return 'awaiting_acceptance';
        }

        if ($this->containsAnyStatusToken($statusTokens, [
            'closed',
            'fechado',
            'delivered',
            'entregue',
            'finished',
            'finalizado',
        ])) {
            return 'delivered';
        }

        if ($this->containsAnyStatusToken($statusTokens, [
            'canceled',
            'cancelled',
            'cancelado',
        ])) {
            return 'rejected';
        }

        if ($this->containsAnyStatusToken($statusTokens, [
            'way',
            'away',
            'en route',
            'in route',
            'on route',
        ])) {
            return 'in_route';
        }

        if ($this->containsAnyStatusToken($statusTokens, [
            'aceito',
            'accepted',
            'accept',
            'confirmed',
            'confirmado',
            'preparando',
            'preparing',
        ]) || $this->hasDeliveryPeople($order)) {
            return 'accepted';
        }

        return '';
    }

    private function hasDeliveryPeople(Order $order): bool
    {
        return $order->getDeliveryPeople() instanceof People || (int) ($order->getDeliveryPeople()?->getId() ?? 0) > 0;
    }

    private function containsAnyStatusToken(array $statusTokens, array $markers): bool
    {
        foreach ($statusTokens as $statusToken) {
            $normalizedStatusToken = strtolower($statusToken);
            foreach ($markers as $marker) {
                if (str_contains($normalizedStatusToken, strtolower($marker))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isDeliveryAwaitingAcceptance(Order $order): bool
    {
        return $this->resolveLifecycleState($order) === 'awaiting_acceptance';
    }

    private function isDeliveryOrder(Order $order): bool
    {
        return $this->normalizeText($order->getOrderType()) === Order::ORDER_TYPE_DELIVERY;
    }

    private function containsAwaitingAcceptanceStatus(array $statusTokens): bool
    {
        return $this->containsAnyStatusToken($statusTokens, self::AWAITING_ACCEPTANCE_MARKERS);
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
        $deviceConfigs = $this->resolveDeliveryDeviceConfigs($deliveryPeople);

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
