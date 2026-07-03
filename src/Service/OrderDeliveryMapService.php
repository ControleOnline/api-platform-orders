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

        $routePlan = $this->buildRoutePlan($provider, $deliveries);

        $payload['deliveries'] = $routePlan['stops'];
        $payload['routePlan'] = $routePlan;
        $payload['totalDeliveries'] = count($routePlan['stops']);

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
            'route' => [
                'priority' => $this->resolveDeliveryRoutePriority($order),
                'etaMinutes' => $this->resolveDeliveryEtaMinutes($order),
                'coordinates' => $this->resolveAddressCoordinates($order->getAddressDestination()),
            ],
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

    private function buildRoutePlan(People $provider, array $deliveries): array
    {
        $originAddress = $this->normalizeAddress($this->resolvePrimaryAddress($provider));
        $originCoordinates = $this->resolveNormalizedAddressCoordinates($originAddress);
        $strategy = $this->resolveRouteStrategy($deliveries, $originCoordinates);
        $orderedDeliveries = $this->orderDeliveriesForRoute($deliveries, $originCoordinates, $strategy);

        $stops = [];
        $segments = [];
        $previousCoordinates = $originCoordinates;
        $totalDistanceKm = 0.0;

        foreach ($orderedDeliveries as $index => $delivery) {
            $currentCoordinates = $this->resolveDeliveryCoordinates($delivery);
            $distanceFromPreviousKm = $this->distanceBetweenCoordinates($previousCoordinates, $currentCoordinates);
            $distanceFromOriginKm = $this->distanceBetweenCoordinates($originCoordinates, $currentCoordinates);

            if (is_float($distanceFromPreviousKm) || is_int($distanceFromPreviousKm)) {
                $totalDistanceKm += (float) $distanceFromPreviousKm;
            }

            $stops[] = $this->attachRouteMetadata(
                $delivery,
                $index + 1,
                $strategy,
                $originCoordinates,
                $previousCoordinates,
                $distanceFromOriginKm,
                $distanceFromPreviousKm,
            );

            $segments[] = [
                'index' => $index + 1,
                'from' => $previousCoordinates,
                'to' => $currentCoordinates,
                'distanceKm' => $this->normalizeDistance($distanceFromPreviousKm),
            ];

            if ($currentCoordinates !== null) {
                $previousCoordinates = $currentCoordinates;
            }
        }

        return [
            'strategy' => $strategy,
            'strategyLabel' => $this->resolveRouteStrategyLabel($strategy),
            'origin' => $originAddress,
            'originCoordinates' => $originCoordinates,
            'totalStops' => count($stops),
            'totalDistanceKm' => $this->normalizeDistance($totalDistanceKm),
            'segments' => $segments,
            'stops' => $stops,
        ];
    }

    private function resolveRouteStrategy(array $deliveries, ?array $originCoordinates): string
    {
        if ($this->hasAnyDeliveryRoutePriority($deliveries)) {
            return 'manual';
        }

        if ($this->hasAnyDeliveryEta($deliveries)) {
            return 'eta';
        }

        if ($originCoordinates !== null && $this->hasAnyDeliveryCoordinates($deliveries)) {
            return 'distance';
        }

        return 'timestamp';
    }

    private function resolveRouteStrategyLabel(string $strategy): string
    {
        return match ($strategy) {
            'manual' => 'Rota manual',
            'eta' => 'Rota por menor tempo',
            'distance' => 'Rota por menor trajeto',
            default => 'Rota por ordem recebida',
        };
    }

    private function orderDeliveriesForRoute(array $deliveries, ?array $originCoordinates, string $strategy): array
    {
        return match ($strategy) {
            'manual' => $this->sortDeliveriesByPriority($deliveries),
            'eta' => $this->sortDeliveriesByEta($deliveries),
            'distance' => $this->sortDeliveriesByDistance($deliveries, $originCoordinates),
            default => $this->sortDeliveriesByFallback($deliveries),
        };
    }

    private function sortDeliveriesByPriority(array $deliveries): array
    {
        usort($deliveries, function (array $left, array $right): int {
            $leftPriority = $this->resolveDeliveryRoutePriority($left);
            $rightPriority = $this->resolveDeliveryRoutePriority($right);

            if ($leftPriority !== null && $rightPriority !== null && $leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            if ($leftPriority !== null) {
                return -1;
            }

            if ($rightPriority !== null) {
                return 1;
            }

            return $this->compareRouteFallback($left, $right);
        });

        return $deliveries;
    }

    private function sortDeliveriesByEta(array $deliveries): array
    {
        usort($deliveries, function (array $left, array $right): int {
            $leftEta = $this->resolveDeliveryEtaMinutes($left);
            $rightEta = $this->resolveDeliveryEtaMinutes($right);

            if ($leftEta !== null && $rightEta !== null && $leftEta !== $rightEta) {
                return $leftEta <=> $rightEta;
            }

            if ($leftEta !== null) {
                return -1;
            }

            if ($rightEta !== null) {
                return 1;
            }

            return $this->compareRouteFallback($left, $right);
        });

        return $deliveries;
    }

    private function sortDeliveriesByDistance(array $deliveries, ?array $originCoordinates): array
    {
        $orderedDeliveries = [];
        $remainingDeliveries = $this->sortDeliveriesByFallback($deliveries);
        $currentCoordinates = $originCoordinates;

        while ($remainingDeliveries !== []) {
            $selectedIndex = null;
            $selectedDistance = null;

            foreach ($remainingDeliveries as $index => $delivery) {
                $deliveryCoordinates = $this->resolveDeliveryCoordinates($delivery);
                $distance = $this->distanceBetweenCoordinates($currentCoordinates, $deliveryCoordinates);

                if ($distance === null) {
                    continue;
                }

                if (
                    $selectedIndex === null
                    || $distance < $selectedDistance
                    || (
                        $distance === $selectedDistance
                        && $this->compareRouteFallback($delivery, $remainingDeliveries[$selectedIndex]) < 0
                    )
                ) {
                    $selectedIndex = $index;
                    $selectedDistance = $distance;
                }
            }

            if ($selectedIndex === null) {
                $orderedDeliveries[] = array_shift($remainingDeliveries);
                continue;
            }

            $selectedDelivery = $remainingDeliveries[$selectedIndex];
            array_splice($remainingDeliveries, $selectedIndex, 1);
            $orderedDeliveries[] = $selectedDelivery;

            $selectedCoordinates = $this->resolveDeliveryCoordinates($selectedDelivery);
            if ($selectedCoordinates !== null) {
                $currentCoordinates = $selectedCoordinates;
            }
        }

        return $orderedDeliveries;
    }

    private function sortDeliveriesByFallback(array $deliveries): array
    {
        usort($deliveries, function (array $left, array $right): int {
            return $this->compareRouteFallback($left, $right);
        });

        return $deliveries;
    }

    private function compareRouteFallback(array $left, array $right): int
    {
        $leftTimestamp = $this->resolveDeliveryFallbackTimestamp($left);
        $rightTimestamp = $this->resolveDeliveryFallbackTimestamp($right);

        if ($leftTimestamp !== $rightTimestamp) {
            return $leftTimestamp <=> $rightTimestamp;
        }

        $leftId = (int) ($left['id'] ?? 0);
        $rightId = (int) ($right['id'] ?? 0);

        return $leftId <=> $rightId;
    }

    private function resolveDeliveryFallbackTimestamp(array $delivery): int
    {
        foreach (['orderDate', 'order_date', 'createdAt', 'created_at', 'alterDate', 'alter_date'] as $fieldName) {
            $timestamp = isset($delivery[$fieldName]) ? strtotime((string) $delivery[$fieldName]) : false;
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return (int) ($delivery['id'] ?? PHP_INT_MAX);
    }

    private function hasAnyDeliveryRoutePriority(array $deliveries): bool
    {
        foreach ($deliveries as $delivery) {
            if ($this->resolveDeliveryRoutePriority($delivery) !== null) {
                return true;
            }
        }

        return false;
    }

    private function hasAnyDeliveryEta(array $deliveries): bool
    {
        foreach ($deliveries as $delivery) {
            if ($this->resolveDeliveryEtaMinutes($delivery) !== null) {
                return true;
            }
        }

        return false;
    }

    private function hasAnyDeliveryCoordinates(array $deliveries): bool
    {
        foreach ($deliveries as $delivery) {
            if ($this->resolveDeliveryCoordinates($delivery) !== null) {
                return true;
            }
        }

        return false;
    }

    private function resolveDeliveryRoutePriority(mixed $delivery): ?int
    {
        if ($delivery instanceof Order) {
            return $this->resolveOrderRoutePriority($delivery);
        }

        if (!is_array($delivery)) {
            return null;
        }

        $payload = $delivery['route'] ?? [];
        $candidates = [
            $payload['priority'] ?? null,
            $payload['order'] ?? null,
            $delivery['routePriority'] ?? null,
            $delivery['route_priority'] ?? null,
            $delivery['routeOrder'] ?? null,
            $delivery['route_order'] ?? null,
            $delivery['stopIndex'] ?? null,
            $delivery['stop_index'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            if (is_numeric($candidate)) {
                return (int) $candidate;
            }

            if (is_string($candidate) && preg_match('/-?\d+/', $candidate, $matches)) {
                return (int) $matches[0];
            }
        }

        return null;
    }

    private function resolveDeliveryEtaMinutes(mixed $delivery): ?float
    {
        if ($delivery instanceof Order) {
            return $this->resolveOrderEtaMinutes($delivery);
        }

        if (!is_array($delivery)) {
            return null;
        }

        $payload = $delivery['route'] ?? [];
        $candidates = [
            $payload['etaMinutes'] ?? null,
            $payload['eta_minutes'] ?? null,
            $delivery['etaMinutes'] ?? null,
            $delivery['eta_minutes'] ?? null,
            $delivery['estimatedEtaMinutes'] ?? null,
            $delivery['estimated_eta_minutes'] ?? null,
            $delivery['expectedArrivedEta'] ?? null,
            $delivery['expected_arrived_eta'] ?? null,
            $delivery['expectedTime'] ?? null,
            $delivery['expected_time'] ?? null,
            $delivery['riderToStoreEta'] ?? null,
            $delivery['rider_to_store_eta'] ?? null,
            $delivery['courierToStoreEta'] ?? null,
            $delivery['courier_to_store_eta'] ?? null,
            $delivery['etaToStore'] ?? null,
            $delivery['eta_to_store'] ?? null,
            $delivery['eta'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $etaMinutes = $this->parseMinutes($candidate);
            if ($etaMinutes !== null) {
                return $etaMinutes;
            }
        }

        return null;
    }

    private function resolveOrderRoutePriority(Order $order): ?int
    {
        $payload = $this->decodePayload($order->getOtherInformations());
        if (!is_array($payload) && !is_object($payload)) {
            return null;
        }

        $text = $this->findPayloadText($payload, [
            'route_order',
            'routeOrder',
            'stopIndex',
            'stop_index',
            'priority',
            'route_priority',
        ]);

        if ($text === '') {
            return null;
        }

        return $this->parseIntegerCandidate($text);
    }

    private function resolveOrderEtaMinutes(Order $order): ?float
    {
        $payload = $this->decodePayload($order->getOtherInformations());
        if (!is_array($payload) && !is_object($payload)) {
            return null;
        }

        $text = $this->findPayloadText($payload, [
            'etaMinutes',
            'eta_minutes',
            'estimatedEtaMinutes',
            'estimated_eta_minutes',
            'expected_arrived_eta',
            'expectedArrivedEta',
            'expected_time',
            'expectedTime',
            'rider_to_store_eta',
            'riderToStoreEta',
            'courier_to_store_eta',
            'courierToStoreEta',
            'eta_to_store',
            'etaToStore',
            'eta',
        ]);

        return $this->parseMinutes($text);
    }

    private function parseMinutes(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalizedValue = strtolower($this->normalizeText($value));
        if ($normalizedValue === '') {
            return null;
        }

        if (preg_match('/^(\d+(?:[.,]\d+)?)$/', $normalizedValue, $matches)) {
            return (float) str_replace(',', '.', $matches[1]);
        }

        if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $normalizedValue, $matches)) {
            return ((int) $matches[1] * 60) + (int) $matches[2];
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)/', $normalizedValue, $matches)) {
            return (float) str_replace(',', '.', $matches[1]);
        }

        return null;
    }

    private function resolveDeliveryCoordinates(mixed $delivery): ?array
    {
        if ($delivery instanceof Order) {
            return $this->resolveAddressCoordinates($delivery->getAddressDestination());
        }

        if (!is_array($delivery)) {
            return null;
        }

        $address = $delivery['address'] ?? null;
        if (!is_array($address)) {
            return null;
        }

        return $this->resolveCoordinatesFromPayload($address);
    }

    private function resolveAddressCoordinates(?Address $address): ?array
    {
        if (!$address instanceof Address) {
            return null;
        }

        return $this->resolveCoordinatesFromPayload([
            'latitude' => $address->getLatitude(),
            'longitude' => $address->getLongitude(),
        ]);
    }

    private function resolveNormalizedAddressCoordinates(?array $address): ?array
    {
        if (!is_array($address)) {
            return null;
        }

        return $this->resolveCoordinatesFromPayload($address);
    }

    private function resolveCoordinatesFromPayload(array $payload): ?array
    {
        $latitude = $this->normalizeCoordinate($payload['latitude'] ?? $payload['lat'] ?? $payload['coordinates']['latitude'] ?? null);
        $longitude = $this->normalizeCoordinate($payload['longitude'] ?? $payload['lng'] ?? $payload['coordinates']['longitude'] ?? null);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function distanceBetweenCoordinates(?array $from, ?array $to): ?float
    {
        if (!is_array($from) || !is_array($to)) {
            return null;
        }

        if (!isset($from['latitude'], $from['longitude'], $to['latitude'], $to['longitude'])) {
            return null;
        }

        $fromLatitude = (float) $from['latitude'];
        $fromLongitude = (float) $from['longitude'];
        $toLatitude = (float) $to['latitude'];
        $toLongitude = (float) $to['longitude'];

        $earthRadiusKm = 6371.0;
        $deltaLatitude = deg2rad($toLatitude - $fromLatitude);
        $deltaLongitude = deg2rad($toLongitude - $fromLongitude);
        $latitude1 = deg2rad($fromLatitude);
        $latitude2 = deg2rad($toLatitude);
        $a = sin($deltaLatitude / 2) ** 2
            + cos($latitude1) * cos($latitude2) * sin($deltaLongitude / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadiusKm * $c;

        return is_finite($distance) ? $distance : null;
    }

    private function attachRouteMetadata(
        array $delivery,
        int $index,
        string $strategy,
        ?array $originCoordinates,
        ?array $previousCoordinates,
        ?float $distanceFromOriginKm,
        ?float $distanceFromPreviousKm,
    ): array {
        $route = is_array($delivery['route'] ?? null) ? $delivery['route'] : [];
        $coordinates = $this->resolveDeliveryCoordinates($delivery);

        $route['index'] = $index;
        $route['strategy'] = $strategy;
        $route['label'] = $this->resolveRouteStrategyLabel($strategy);

        if ($coordinates !== null) {
            $route['coordinates'] = $coordinates;
        }

        if (($priority = $this->resolveDeliveryRoutePriority($delivery)) !== null) {
            $route['priority'] = $priority;
        }

        if (($etaMinutes = $this->resolveDeliveryEtaMinutes($delivery)) !== null) {
            $route['etaMinutes'] = $etaMinutes;
        }

        if ($distanceFromOriginKm !== null) {
            $route['distanceFromOriginKm'] = $this->normalizeDistance($distanceFromOriginKm);
        }

        if ($distanceFromPreviousKm !== null) {
            $route['distanceFromPreviousKm'] = $this->normalizeDistance($distanceFromPreviousKm);
        }

        if ($previousCoordinates !== null) {
            $route['previousCoordinates'] = $previousCoordinates;
        }

        if ($originCoordinates !== null) {
            $route['originCoordinates'] = $originCoordinates;
        }

        $delivery['route'] = $route;
        $delivery['routeOrder'] = $index;

        return $delivery;
    }

    private function normalizeDistance(?float $distance): ?float
    {
        if ($distance === null || !is_finite($distance)) {
            return null;
        }

        return round($distance, 2);
    }

    private function parseIntegerCandidate(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        $normalizedValue = $this->normalizeText($value);
        if ($normalizedValue === '') {
            return null;
        }

        if (preg_match('/-?\d+/', $normalizedValue, $matches)) {
            return (int) $matches[0];
        }

        return null;
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
