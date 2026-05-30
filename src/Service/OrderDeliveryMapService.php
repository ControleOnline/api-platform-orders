<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Address;
use ControleOnline\Entity\Config;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class OrderDeliveryMapService
{
    public const GOOGLE_MAPS_WEB_API_KEY_CONFIG_KEY = 'web-google-maps-api-key';
    public const GOOGLE_MAPS_ANDROID_API_KEY_CONFIG_KEY = 'android-google-maps-api-key';

    private const WAY_STATUSES = ['way', 'away'];
    private const CLOSED_STATUS = 'closed';
    private const CLOSED_LIMIT = 10;
    private const WAY_LIMIT = 100;

    public function __construct(
        private EntityManagerInterface $manager,
        private ConfigService $configService,
        private PeopleService $peopleService,
    ) {
    }

    public function buildPayload(mixed $providerReference): array
    {
        $provider = $this->resolveProvider($providerReference);
        $this->assertCanAccessProvider($provider);

        $webGoogleMapsApiKey = $this->normalizeTextConfig(
            $this->configService->getConfig(
                $provider,
                self::GOOGLE_MAPS_WEB_API_KEY_CONFIG_KEY,
            ),
        );

        $androidGoogleMapsApiKey = $this->normalizeTextConfig(
            $this->configService->getConfig(
                $provider,
                self::GOOGLE_MAPS_ANDROID_API_KEY_CONFIG_KEY,
            ),
        );

        $payload = [
            'enabled' => $webGoogleMapsApiKey !== '' || $androidGoogleMapsApiKey !== '',
            'webGoogleMapsApiKey' => $webGoogleMapsApiKey,
            'androidGoogleMapsApiKey' => $androidGoogleMapsApiKey,
            'provider' => $this->normalizeProvider($provider),
            'rules' => [
                'wayStatuses' => self::WAY_STATUSES,
                'closedStatus' => self::CLOSED_STATUS,
                'closedLimit' => self::CLOSED_LIMIT,
                'closedDateFilter' => false,
            ],
            'deliveries' => [],
            'totalDeliveries' => 0,
        ];

        if ($webGoogleMapsApiKey === '' && $androidGoogleMapsApiKey === '') {
            return $payload;
        }

        $deliveries = array_values(array_filter(
            array_map(
                fn(Order $order): array => $this->normalizeDeliveryOrder($order),
                $this->fetchDeliveryOrders($provider),
            ),
            fn(array $delivery): bool => (string) ($delivery['address']['formatted'] ?? '') !== '',
        ));

        $payload['deliveries'] = $deliveries;
        $payload['totalDeliveries'] = count($deliveries);

        return $payload;
    }

    public function normalizeDeliveryOrder(Order $order): array
    {
        $status = $order->getStatus();

        return [
            'id' => $order->getId(),
            '@id' => sprintf('/orders/%d', (int) $order->getId()),
            'app' => $this->normalizeText($order->getApp()),
            'orderType' => $this->normalizeText($order->getOrderType()),
            'price' => (float) $order->getPrice(),
            'orderDate' => $this->formatDateTime($order->getOrderDate()),
            'alterDate' => $this->formatDateTime($order->getAlterDate()),
            'displayCode' => $this->resolveDisplayCode($order),
            'status' => $status instanceof Status ? [
                'id' => $status->getId(),
                'status' => $this->normalizeText($status->getStatus()),
                'realStatus' => $this->normalizeText($status->getRealStatus()),
                'color' => $this->normalizeText($status->getColor()),
            ] : null,
            'client' => $this->normalizePeople($order->getClient()),
            'address' => $this->normalizeAddress(
                $order->getAddressDestination(),
                $this->resolveFallbackAddressText($order),
            ),
        ];
    }

    private function resolveProvider(mixed $providerReference): People
    {
        $providerId = (int) preg_replace('/\D+/', '', (string) $providerReference);

        if ($providerId <= 0) {
            throw new BadRequestHttpException('Provider e obrigatorio');
        }

        $provider = $this->manager->getRepository(People::class)->find($providerId);

        if (!$provider instanceof People) {
            throw new BadRequestHttpException('Provider invalido');
        }

        return $provider;
    }

    private function assertCanAccessProvider(People $provider): void
    {
        $currentPeople = $this->peopleService->getMyPeople();

        if (
            $currentPeople instanceof People
            && (int) $currentPeople->getId() === (int) $provider->getId()
        ) {
            return;
        }

        if (!$this->peopleService->canAccessCompany($provider, $currentPeople)) {
            throw new AccessDeniedHttpException('Acesso negado ao provider informado');
        }
    }

    /**
     * @return Order[]
     */
    private function fetchDeliveryOrders(People $provider): array
    {
        $ordersById = [];

        foreach ($this->fetchWayOrders($provider) as $order) {
            $ordersById[(int) $order->getId()] = $order;
        }

        foreach ($this->fetchClosedOrders($provider) as $order) {
            $ordersById[(int) $order->getId()] ??= $order;
        }

        $orders = array_values($ordersById);
        usort($orders, fn(Order $left, Order $right): int => $this->compareDeliveryOrders($left, $right));

        return $orders;
    }

    /**
     * @return Order[]
     */
    private function fetchWayOrders(People $provider): array
    {
        return $this->createDeliveryOrdersQuery($provider)
            ->andWhere('LOWER(orderStatus.status) IN (:wayStatuses)')
            ->setParameter('wayStatuses', self::WAY_STATUSES)
            ->setMaxResults(self::WAY_LIMIT)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Order[]
     */
    private function fetchClosedOrders(People $provider): array
    {
        return $this->createDeliveryOrdersQuery($provider)
            ->andWhere('LOWER(orderStatus.status) = :closedStatus')
            ->setParameter('closedStatus', self::CLOSED_STATUS)
            ->setMaxResults(self::CLOSED_LIMIT)
            ->getQuery()
            ->getResult();
    }

    private function createDeliveryOrdersQuery(People $provider)
    {
        return $this->manager->getRepository(Order::class)
            ->createQueryBuilder('deliveryOrder')
            ->addSelect(
                'orderStatus',
                'client',
                'addressDestination',
                'street',
                'district',
                'city',
                'state',
                'cep',
            )
            ->leftJoin('deliveryOrder.status', 'orderStatus')
            ->leftJoin('deliveryOrder.client', 'client')
            ->leftJoin('deliveryOrder.addressDestination', 'addressDestination')
            ->leftJoin('addressDestination.street', 'street')
            ->leftJoin('street.district', 'district')
            ->leftJoin('district.city', 'city')
            ->leftJoin('city.state', 'state')
            ->leftJoin('street.cep', 'cep')
            ->andWhere('deliveryOrder.provider = :provider')
            ->andWhere('deliveryOrder.orderType = :orderType')
            ->setParameter('provider', $provider)
            ->setParameter('orderType', Order::ORDER_TYPE_SALE)
            ->orderBy('deliveryOrder.alterDate', 'DESC');
    }

    private function compareDeliveryOrders(Order $left, Order $right): int
    {
        $leftStatus = strtolower($this->normalizeText($left->getStatus()?->getStatus()));
        $rightStatus = strtolower($this->normalizeText($right->getStatus()?->getStatus()));
        $leftPriority = in_array($leftStatus, self::WAY_STATUSES, true) ? 0 : 1;
        $rightPriority = in_array($rightStatus, self::WAY_STATUSES, true) ? 0 : 1;

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return $this->timestamp($right->getAlterDate()) <=> $this->timestamp($left->getAlterDate());
    }

    private function normalizeAddress(?Address $address, string $fallbackText = ''): array
    {
        $street = $address?->getStreet();
        $district = $street?->getDistrict();
        $city = $district?->getCity();
        $state = $city?->getState();
        $cep = $street?->getCep();
        $streetLine = $this->joinText([
            $this->normalizeText($street?->getStreet()),
            $this->normalizeText($address?->getNumber()),
        ], ', ');
        $cityStateLine = $this->joinText([
            $this->normalizeText($city?->getCity()),
            $this->normalizeText($state?->getUf() ?: $state?->getState()),
        ], ' / ');
        $formatted = $this->joinText([
            $streetLine,
            $this->normalizeText($district?->getDistrict()),
            $cityStateLine,
            $this->normalizeText($cep?->getCep()),
        ]);

        return [
            'id' => $address?->getId(),
            'nickname' => $this->normalizeText($address?->getNickname()),
            'streetLine' => $streetLine,
            'district' => $this->normalizeText($district?->getDistrict()),
            'city' => $this->normalizeText($city?->getCity()),
            'state' => $this->normalizeText($state?->getUf() ?: $state?->getState()),
            'postalCode' => $this->normalizeText($cep?->getCep()),
            'complement' => $this->normalizeText($address?->getComplement()),
            'formatted' => $formatted ?: $fallbackText,
            'latitude' => $this->normalizeCoordinate($address?->getLatitude()),
            'longitude' => $this->normalizeCoordinate($address?->getLongitude()),
        ];
    }

    private function normalizeProvider(People $provider): array
    {
        $normalizedProvider = $this->normalizePeople($provider) ?? [];
        $normalizedProvider['address'] = $this->normalizeAddress(
            $this->resolvePrimaryAddress($provider),
        );

        return $normalizedProvider;
    }

    private function resolvePrimaryAddress(People $people): ?Address
    {
        $addresses = $people->getAddress();

        if (!is_iterable($addresses)) {
            return null;
        }

        foreach ($addresses as $address) {
            if ($address instanceof Address) {
                return $address;
            }
        }

        return null;
    }

    private function normalizePeople(?People $people): ?array
    {
        if (!$people instanceof People) {
            return null;
        }

        return [
            'id' => $people->getId(),
            '@id' => sprintf('/people/%d', (int) $people->getId()),
            'name' => $this->normalizeText($people->getName()),
            'alias' => $this->normalizeText($people->getAlias()),
        ];
    }

    private function resolveDisplayCode(Order $order): string
    {
        $payload = $this->decodePayload($order->getOtherInformations());

        return $this->findPayloadText($payload, [
            'order_index',
            'displayId',
            'display_id',
            'code',
            'pickup_code',
            'handover_code',
        ]);
    }

    private function resolveFallbackAddressText(Order $order): string
    {
        $payload = $this->decodePayload($order->getOtherInformations());

        return $this->findPayloadText($payload, [
            'formatted_address',
            'formattedAddress',
            'full_address',
            'address',
            'receive_address',
            'delivery_address',
        ]);
    }

    private function findPayloadText(mixed $payload, array $keys, int $depth = 0): string
    {
        if ($depth > 4) {
            return '';
        }

        if (is_object($payload)) {
            $payload = get_object_vars($payload);
        }

        if (!is_array($payload)) {
            return $this->normalizeText($payload);
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_scalar($value)) {
                return $this->normalizeText($value);
            }

            $nestedText = $this->findPayloadText($value, [
                'formatted_address',
                'formattedAddress',
                'full_address',
                'address',
                'street',
                'street_name',
                'order_index',
                'displayId',
                'display_id',
                'code',
            ], $depth + 1);

            if ($nestedText !== '') {
                return $nestedText;
            }
        }

        foreach (['order_info', 'identifiers', 'data', 'order', 'payload', 'delivery'] as $containerKey) {
            if (!array_key_exists($containerKey, $payload)) {
                continue;
            }

            $nestedText = $this->findPayloadText($payload[$containerKey], $keys, $depth + 1);
            if ($nestedText !== '') {
                return $nestedText;
            }
        }

        return '';
    }

    private function decodePayload(mixed $payload): mixed
    {
        if (is_array($payload) || is_object($payload)) {
            return $payload;
        }

        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }

        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }

    private function normalizeTextConfig(?string $value): string
    {
        $normalizedValue = trim((string) $value);

        if ($normalizedValue === '') {
            return '';
        }

        try {
            $decodedValue = json_decode($normalizedValue, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $normalizedValue;
        }

        return is_scalar($decodedValue) ? $this->normalizeText($decodedValue) : $normalizedValue;
    }

    private function formatDateTime(?DateTimeInterface $dateTime): ?string
    {
        return $dateTime?->format(DateTimeInterface::ATOM);
    }

    private function timestamp(?DateTimeInterface $dateTime): int
    {
        return $dateTime ? $dateTime->getTimestamp() : 0;
    }

    private function normalizeCoordinate(mixed $value): ?float
    {
        $coordinate = (float) $value;

        return $coordinate !== 0.0 ? $coordinate : null;
    }

    private function joinText(array $parts, string $separator = ' - '): string
    {
        return implode(
            $separator,
            array_values(array_filter(array_map(
                fn(mixed $value): string => $this->normalizeText($value),
                $parts,
            ))),
        );
    }

    private function normalizeText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }
}
