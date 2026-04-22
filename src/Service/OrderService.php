<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\DisplayQueue;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;


class OrderService
{
    public const ORDER_TYPE_CART = Order::ORDER_TYPE_CART;
    public const ORDER_TYPE_QUOTE = Order::ORDER_TYPE_QUOTE;
    public const ORDER_TYPE_SALE = Order::ORDER_TYPE_SALE;

    private const DRAFT_ORDER_APPS = [
        'pos',
        'shop',
    ];

    private string $displayDeviceType = 'DISPLAY';
    private string $displayConfigKey = 'display-id';
    private $request;

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $peopleService,
        private StatusService $statusService,
        private OrderProductQueueService $orderProductQueueService,
        private WebsocketClient $websocketClient,
        RequestStack $requestStack
    ) {
        $this->request  = $requestStack->getCurrentRequest();
    }

    public function calculateOrderPrice(Order $order)
    {
        $sql = 'UPDATE orders O
                JOIN (
                    SELECT order_id, IFNULL(SUM(total), 0) AS new_total
                    FROM order_product
                    WHERE order_product_id IS NULL
                    GROUP BY order_id
                ) AS subquery ON O.id = subquery.order_id
                SET O.price = IFNULL(subquery.new_total, 0)
                WHERE O.id = :order_id;
                ';
        $connection = $this->manager->getConnection();
        $statement = $connection->prepare($sql);
        $statement->bindValue(':order_id', $order->getId(), Type::getType('integer'));
        $statement->executeStatement();

        return $order;
    }

    public function calculateGroupProductPrice(Order $order)
    {
        $sql = 'UPDATE order_product OPO
                JOIN (
                    SELECT 
                        OP.order_product_id,
                        (CASE 
                            WHEN PG.price_calculation = "biggest" THEN MAX(PGP.price)
                            WHEN PG.price_calculation = "sum" THEN SUM(PGP.price)
                            WHEN PG.price_calculation = "average" THEN AVG(PGP.price) 
                            WHEN PG.price_calculation = "free" THEN 0
                            ELSE NULL
                        END) AS calculated_price
                    FROM order_product OP
                    INNER JOIN product_group PG ON OP.product_group_id = PG.id
                    INNER JOIN product_group_product PGP ON PGP.product_group_id = OP.product_group_id AND PGP.product_child_id = OP.product_id
                    INNER JOIN product P ON P.id = OP.product_id
                    WHERE OP.parent_product_id IS NOT NULL AND OP.order_id = :order_id
                    GROUP BY OP.order_product_id, PG.id
                ) AS subquery ON OPO.id = subquery.order_product_id
                SET OPO.price = subquery.calculated_price,
                    OPO.total = (subquery.calculated_price * OPO.quantity)
                ';
        $connection = $this->manager->getConnection();
        $statement = $connection->prepare($sql);
        $statement->bindValue(':order_id', $order->getId(), Type::getType('integer'));
        $statement->executeStatement();

        return $order;
    }

    public function createOrder(People $receiver, People $payer, $app)
    {
        $startsAsCart = $this->shouldStartAsCart($app);
        $status = $startsAsCart
            ? $this->statusService->discoveryStatus('open', 'open', 'order')
            : $this->statusService->discoveryStatus('pending', 'waiting payment', 'order');

        $order = new Order();
        $order->setProvider($receiver);
        $order->setClient($payer);
        $order->setPayer($payer);
        $order->setOrderType(
            $startsAsCart ? self::ORDER_TYPE_CART : self::ORDER_TYPE_SALE
        );
        $order->setStatus($status);
        $order->setApp($app);

        $this->manager->persist($order);
        $this->manager->flush();
        return $order;
    }

    public function findOrderById(int $orderId): ?Order
    {
        return $this->manager->getRepository(Order::class)->find($orderId);
    }

    public function isMarketplaceIntegrationOrder(Order $order): bool
    {
        return $this->isMarketplaceApp($order->getApp());
    }

    public function shouldStartAsCart(?string $app): bool
    {
        return in_array(
            $this->normalizeStatusValue($app),
            self::DRAFT_ORDER_APPS,
            true
        );
    }

    public function isProductionOrder(Order $order): bool
    {
        return $this->normalizeStatusValue($order->getOrderType()) === self::ORDER_TYPE_SALE;
    }

    public function normalizeDraftCartOrder(Order $order): bool
    {
        if (
            !$this->shouldStartAsCart($order->getApp())
            || $this->hasClosedInvoices($order)
            || $this->normalizeStatusValue($order->getOrderType()) === self::ORDER_TYPE_CART
        ) {
            return false;
        }

        $order->setOrderType(self::ORDER_TYPE_CART);
        $this->applyQueueStateForOrder($order);
        return true;
    }

    public function convertDraftOrderToSale(Order $order): bool
    {
        if ($this->normalizeStatusValue($order->getOrderType()) === self::ORDER_TYPE_SALE) {
            return false;
        }

        $order->setOrderType(self::ORDER_TYPE_SALE);

        foreach ($order->getOrderProducts() as $orderProduct) {
            $product = $orderProduct->getProduct();
            if ($product === null || $orderProduct->getOutInventory()) {
                continue;
            }

            $orderProduct->setOutInventory($product->getDefaultOutInventory());
            $this->manager->persist($orderProduct);
        }

        return true;
    }

    public function preUpdate(Order $order): void
    {
        if (
            !$this->isMarketplaceIntegrationOrder($order)
            || !$this->isDirectOrderResourceEditRequest()
        ) {
            return;
        }

        throw new BadRequestHttpException(
            'Pedidos de integracao nao podem ser editados diretamente. Use as acoes do pedido para alterar status.'
        );
    }

    public function securityFilter(QueryBuilder $queryBuilder, $resourceClass = null, $applyTo = null, $rootAlias = null): void
    {
        $companies   = $this->peopleService->getMyCompanies();

        if ($invoice = $this->request->query->get('invoiceId', null)) {
            $queryBuilder->join(sprintf('%s.invoice', $rootAlias), 'OrderInvoice');
            $queryBuilder->andWhere(sprintf('OrderInvoice.invoice IN(:invoice)', $rootAlias, $rootAlias));
            $queryBuilder->setParameter('invoice', $invoice);
        }

        $queryBuilder->andWhere(sprintf('%s.client IN(:companies) OR %s.provider IN(:companies)', $rootAlias, $rootAlias));
        $queryBuilder->setParameter('companies', $companies);

        if ($provider = $this->request->query->get('provider', null)) {
            $queryBuilder->andWhere(sprintf('%s.provider IN(:provider)', $rootAlias));
            $queryBuilder->setParameter('provider', preg_replace("/[^0-9]/", "", $provider));
        }

        if ($client = $this->request->query->get('client', null)) {
            $queryBuilder->andWhere(sprintf('%s.client IN(:client)', $rootAlias));
            $queryBuilder->setParameter('client', preg_replace("/[^0-9]/", "", $client));
        }

        if ($this->isOrdersQueueRequest()) {
            $queryBuilder->andWhere(sprintf('%s.orderType = :displayOrderType', $rootAlias));
            $queryBuilder->setParameter('displayOrderType', self::ORDER_TYPE_SALE);
        }
    }

    public function postPersist(Order $order): void
    {
        $provider = $order->getProvider();
        if (!$provider) {
            return;
        }

        $deviceConfigs = $this->manager->getRepository(DeviceConfig::class)->findBy([
            'people' => $provider,
        ]);

        $baseEvent = [[
            'store' => 'orders',
            'event' => 'order.created',
            'company' => $provider->getId(),
            'order' => $order->getId(),
            'realStatus' => $this->normalizeStatusValue($order->getStatus()?->getRealStatus()),
            'sentAt' => date(DATE_ATOM),
        ]];

        if (!$this->isPreparationOrder($order)) {
            $this->pushToDeviceConfigs($deviceConfigs, $baseEvent);
            return;
        }

        $alertDeviceConfigs = $this->resolvePreparationAlertDeviceConfigs($provider, $order);

        $this->pushToDeviceConfigs(
            $this->excludeDeviceConfigs($deviceConfigs, $alertDeviceConfigs),
            $baseEvent
        );

        $this->pushToDeviceConfigs(
            $alertDeviceConfigs,
            [[
                ...$baseEvent[0],
                'alertSound' => true,
            ]]
        );
    }

    public function postUpdate(Order $order): void
    {
        $this->applyQueueStateForOrder($order);

        $provider = $order->getProvider();
        if ($provider) {
            $this->pushToCompanyDevices($provider, [[
                'store' => 'orders',
                'event' => 'order.updated',
                'company' => $provider->getId(),
                'order' => $order->getId(),
                'realStatus' => $this->normalizeStatusValue($order->getStatus()?->getRealStatus()),
                'status' => $this->normalizeStatusValue($order->getStatus()?->getStatus()),
                'sentAt' => date(DATE_ATOM),
            ]]);
        }
    }

    private function pushToCompanyDevices(People $company, array $events): void
    {
        $this->pushToDeviceConfigs(
            $this->manager->getRepository(DeviceConfig::class)->findBy([
                'people' => $company,
            ]),
            $events
        );
    }

    private function pushToDeviceConfigs(array $deviceConfigs, array $events): void
    {
        if (empty($deviceConfigs)) {
            return;
        }

        $payload = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        $sentDevices = [];
        foreach ($deviceConfigs as $deviceConfig) {
            if (!$deviceConfig instanceof DeviceConfig) {
                continue;
            }

            $device = $deviceConfig->getDevice();
            $deviceId = $device->getId();

            if (isset($sentDevices[$deviceId])) {
                continue;
            }

            $sentDevices[$deviceId] = true;
            $this->websocketClient->push($device, $payload);
        }
    }

    private function isPreparationOrder(Order $order): bool
    {
        return $this->normalizeStatusValue($order->getStatus()?->getRealStatus()) === 'open'
            && $this->isProductionOrder($order);
    }

    private function isMarketplaceApp(?string $app): bool
    {
        $normalizedApp = $this->normalizeStatusValue($app);

        return in_array($normalizedApp, [
            $this->normalizeStatusValue(Order::APP_IFOOD),
            $this->normalizeStatusValue(Order::APP_FOOD99),
        ], true);
    }

    private function applyQueueStateForOrder(Order $order): void
    {
        $this->orderProductQueueService->syncByOrderStatus($order);
    }

    private function hasClosedInvoices(Order $order): bool
    {
        foreach ($order->getInvoice() as $orderInvoice) {
            $invoice = $orderInvoice->getInvoice();
            if ($this->normalizeStatusValue($invoice?->getStatus()?->getRealStatus()) === 'closed') {
                return true;
            }
        }

        return false;
    }

    private function excludeDeviceConfigs(array $deviceConfigs, array $excludedDeviceConfigs): array
    {
        if (empty($deviceConfigs) || empty($excludedDeviceConfigs)) {
            return $deviceConfigs;
        }

        $excludedDeviceIds = [];
        foreach ($excludedDeviceConfigs as $deviceConfig) {
            if (!$deviceConfig instanceof DeviceConfig) {
                continue;
            }

            $excludedDeviceIds[$deviceConfig->getDevice()->getId()] = true;
        }

        return array_values(array_filter(
            $deviceConfigs,
            function ($deviceConfig) use ($excludedDeviceIds): bool {
                if (!$deviceConfig instanceof DeviceConfig) {
                    return false;
                }

                return !isset($excludedDeviceIds[$deviceConfig->getDevice()->getId()]);
            }
        ));
    }

    private function resolvePreparationAlertDeviceConfigs(
        People $company,
        Order $order
    ): array {
        $deviceConfigs = array_values(array_filter(
            $this->manager->getRepository(DeviceConfig::class)->findBy([
                'people' => $company,
            ]),
            fn($deviceConfig) => $this->isDisplayDeviceConfig($deviceConfig)
        ));

        if (empty($deviceConfigs)) {
            return [];
        }

        $displayIds = $this->resolveOrderDisplayIds($order);
        if (empty($displayIds)) {
            return $deviceConfigs;
        }

        $matchedDeviceConfigs = array_values(array_filter(
            $deviceConfigs,
            function (DeviceConfig $deviceConfig) use ($displayIds): bool {
                $configs = $deviceConfig->getConfigs(true);
                if (!is_array($configs)) {
                    return false;
                }

                $displayId = $this->normalizeEntityId(
                    $configs[$this->displayConfigKey] ?? null
                );

                return $displayId !== null && isset($displayIds[$displayId]);
            }
        ));

        return !empty($matchedDeviceConfigs) ? $matchedDeviceConfigs : $deviceConfigs;
    }

    private function resolveOrderDisplayIds(Order $order): array
    {
        $queues = [];

        foreach ($order->getOrderProducts() as $orderProduct) {
            if ($orderProduct->getOrderProduct() !== null) {
                continue;
            }

            foreach ($orderProduct->getOrderProductQueues() as $queueEntry) {
                $queue = $queueEntry->getQueue();
                $queueId = $this->normalizeEntityId($queue?->getId());

                if ($queue !== null && $queueId !== null) {
                    $queues[$queueId] = $queue;
                }
            }
        }

        if (empty($queues)) {
            return [];
        }

        $displayRows = $this->manager->getRepository(DisplayQueue::class)->findBy([
            'queue' => array_values($queues),
        ]);

        $displayIds = [];
        foreach ($displayRows as $displayRow) {
            $displayId = $this->normalizeEntityId($displayRow->getDisplay()?->getId());
            if ($displayId !== null) {
                $displayIds[$displayId] = true;
            }
        }

        return $displayIds;
    }

    private function isDisplayDeviceConfig(mixed $deviceConfig): bool
    {
        return $deviceConfig instanceof DeviceConfig &&
            strtoupper(trim((string) $deviceConfig->getType())) === $this->displayDeviceType;
    }

    private function normalizeStatusValue(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function normalizeEntityId(mixed $value): ?int
    {
        if (is_object($value) && method_exists($value, 'getId')) {
            $value = $value->getId();
        }

        $normalized = preg_replace('/\D+/', '', (string) $value);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        return (int) $normalized;
    }

    private function isDirectOrderResourceEditRequest(): bool
    {
        if (!$this->request) {
            return false;
        }

        $method = strtoupper((string) $this->request->getMethod());
        if (!in_array($method, ['PUT', 'PATCH'], true)) {
            return false;
        }

        return (bool) preg_match('#^/orders/\d+$#', (string) $this->request->getPathInfo());
    }

    private function isOrdersQueueRequest(): bool
    {
        if (!$this->request) {
            return false;
        }

        return (string) $this->request->getPathInfo() === '/orders-queue';
    }
}
