<?php

/*
 * @agents Order service contract.
 * ## Scope
 * - Central sales order module.
 * - Covers `Order`, `OrderProduct`, `OrderInvoice`, cart, order actions, cart discovery, and order-related print flows.
 *
 * ## When to use
 * - Prompts about order, order item, operational checkout, order printing, order actions, and the commercial order lifecycle.
 *
 * ## Boundaries
 * - `orders` owns the operational order rule.
 * - `financial` still owns `Invoice`, `Wallet`, and payment methods.
 * - `integration` still owns webhooks and external gateways.
 * - `extra_data` and `extra_fields` must not store rich snapshots of order, delivery, payment, or operational status when the data already has a canonical destination in `Order`, `OrderInvoice`, or `Invoice`. In this layer, those fields may only carry remote IDs and codes that do not yet have a materialized column equivalent.
 * - When a flow touches both order and payment, the order rule stays here and the financial/integration layer stays in the corresponding modules.
 * - For sales, the canonical draft/cart order uses `orderType = cart`. `quote` should no longer represent a sales cart.
 * - `SHOP` order confirmation must reject carts without `addressDestination`; the cart can exist as a draft, but it cannot become a sale without a delivery address.
 * - In `tab/table/stamp` flows, the root financial order remains an `Order` in the `orders` module. Child orders and invoices must converge on that root, without a parallel contract outside `mainOrderId` and `OrderInvoice`.
 * - The `Order` read serializer must expose `mainOrder.externalCode` in the groups used by list and detail views. That value is the table number and must not depend on `otherInformations` in the frontend.
 * - `ready`, `cancel`, and `delivered` must come from the main order action flow (`OrderActionService`/`OrderActionController`). Do not create parallel status-change paths for KDS, marketplace, or device flows.
 * - `PUT /orders/{id}` is not a free-form state editor. That flow can only update non-operational business fields and normalize `quote`/`cart`/`sale` when the rule allows it; status changes stay in action and financial flows.
 * - The canonical 99 integration name in the backend is `Food99` whenever the order or context needs to identify the platform.
 * - `/orders-queue`, consumed by displays/KDS, must expose only sales orders (`orderType = sale`). Drafts and carts (`cart`) do not belong in that operational view.
 * - `/orders-queue` can expose the visual component tree through the dedicated `orders-queue-tree:read` group. That group must not include cyclical backrefs such as `orderProduct`.
 * - `orders` and `tv` continue to consume the full `OrderProduct` tree; `showInParentQueue` only decides the visual hierarchy on the consumer side, not whether the item exists in the collection.
 * - The operational view must not synthesize children or rewrite the queue to simulate hidden parent items.
 * - `Order.channel` is limited to `pos`, `shop`, `totem`, and `external`; concrete platforms remain in `app`.
 * - `fulfillmentType` records commercial intent only. Fulfillment execution, quantities, actors, devices, and idempotency belong to the dedicated fulfillment ledger.
 * - `payBeforeProduction=false` means payment is not a prerequisite; it does not require production before payment and must not create a `produce_before_payment` policy.
 * - Payment capability is independent from POS operation mode. The backend contract in `OrderCommercialContextService` must protect direct payment routes, while `waiter` and `cashier` remain workflow presets only.
 * - A confirmed `sale` never regresses to `cart`; post-confirmation corrections use their dedicated audited actions.
 * - In order printing, `ProductGroup.showInDisplay=false` must hide only the group title. Items and components continue to be printed and grouped.
 * - Paper queue printing must mirror the matching display: materialized items must not show `2x`, while internal non-materialized items may only show a quantity prefix above 1.
 * - Shop loyalty uses a root order with `orderType = fidelity`. Closed and eligible `sale` orders are linked as children through `mainOrderId`; when the card is already full, the next closed sale with the gift closes that card and a closed sale without the gift opens the next card.
 * - The `OrderProduct` collection must answer with the internal default payload (`member`, `totalItems`, `search`, `@context`, `@id`, `@type`) even when the read comes from the standard API Platform flow. Do not push format fallbacks to the frontend.
 * - `OrderProduct` must remain exposed as an API Platform entity. Do not use a dedicated controller just to reformat the collection; that adaptation belongs in normalizers/shared infrastructure.
 * - `GET /orders/{id}` must stay stable and lean for opening the order details. Do not expand grouping relations (`orderProduct`, `parentProduct`, `productGroup`) in that payload if it increases heavy or cyclical serialization risk.
 * - Aggregated report and TV queries must originate in `OrderRepository`, not in services. `OrderReportSummaryResolver` only orchestrates the return and may expose extra blocks such as `operationalInsights`, but the queries remain in the `orders` domain.
 * - When TV requests a specific `insight`, `OrderReportSummaryResolver` must return only that block inside `operationalInsights`, while keeping the full contract only for screens that request the full summary.
 * - `delivery_people_id` is the canonical order field for the courier chosen by the store and must be maintained by the `orders` rule itself.
 * - The delivery view uses the standard `/orders` collection with the logged courier as `provider` and `orderType=delivery`, preserving the `people_link` restriction for `courier`.
 * - In `Food99` logistics child orders, `provider` is the courier, `payer` is `99 Food`, `client` is the parent order company, `deliveryContact` is the parent order client, `addressOrigin` must always be filled, and the child must not copy `otherInformations`.
 * - When the full customization hierarchy is needed in the frontend, the rich source must be the `OrderProduct` collection, keeping the `Order` serializer safe and predictable.
 * - In `/order_invoices`, expanded `Invoice` data for the frontend must use a dedicated minimal group, separate from `invoice:read`. Do not reuse the full `Invoice` serializer in this collection, to avoid excessive joins and the `1116 Too many tables` error.
 * - In `PUT /order_products/{id}`, when `sub_products` is provided, the backend must replace the item's current component collection. Do not accumulate old children with new ones when reopening customization.
 * - In `DELETE /order_products/{id}`, removing a customizable item must delete the component tree and queues first through the `orderProduct` link. Do not use `parentProduct` to decide which children to remove.
 * - `Order` lists consumed by React `DefaultTable` need aligned `CustomOrFilter`, `OrderFilter`, and `DateFilter` support in the store, with date ordering based on the persisted backend value.
 * - Aggregated data for the operational delivery map must come from the single `/orders-delivery-map` endpoint, keeping the backend rule: `way`/`away` statuses are not day-limited and `closed` is limited to the 10 most recent closed orders, without a date filter.
 */


namespace ControleOnline\Service;

use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\DisplayQueue;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\Status;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

/** Methods shared by the original class; contracts and visibility are unchanged. */
trait OrderServiceOperations3
{
    private function assertDirectOrderUpdateAllowed(Order $order, array $payload): void
    {
        /*
         * @agents Direct order PUT is only for business data adjustments and type normalization;
         * status transitions stay in the operational flows.
         */
        if (array_key_exists('orderProducts', $payload)) {
            throw new BadRequestHttpException(
                'A atualizacao direta do pedido nao pode alterar produtos. Use as acoes de produtos.'
            );
        }

        if (array_key_exists('status', $payload)) {
            $requestedStatusId = $this->resolvePayloadEntityId($payload['status']);
            if ($requestedStatusId === null) {
                throw new BadRequestHttpException(
                    'Status do pedido invalido para atualizacao direta.'
                );
            }

            $currentStatusId = $this->resolvePayloadEntityId($order->getStatus()?->getId());
            if ($currentStatusId !== $requestedStatusId) {
                throw new BadRequestHttpException(
                    'Status do pedido nao pode ser alterado por PUT. Use as acoes do pedido.'
                );
            }
        }

        if (
            array_key_exists('payBeforeProduction', $payload)
            || array_key_exists('pay_before_production', $payload)
        ) {
            throw new BadRequestHttpException(
                'payBeforeProduction e uma politica server-side e nao pode ser alterada pelo payload.'
            );
        }

        if (
            $this->normalizeStatusValue($order->getOrderType()) === self::ORDER_TYPE_SALE
            && array_intersect(
                ['provider', 'channel', 'fulfillmentType', 'payBeforeProduction', 'mainOrder', 'mainOrderId'],
                array_keys($payload),
            ) !== []
        ) {
            throw new BadRequestHttpException(
                'Contexto comercial de sale nao pode ser alterado por PUT.'
            );
        }

        if (!array_key_exists('orderType', $payload)) {
            return;
        }

        $currentOrderType = $this->normalizeStatusValue($order->getOrderType());
        $requestedOrderType = $this->normalizeOrderTypeValue($payload['orderType'] ?? null);

        if ($requestedOrderType === 'quote') {
            $requestedOrderType = self::ORDER_TYPE_CART;
        }

        if ($requestedOrderType === '') {
            throw new BadRequestHttpException(
                'Tipo de pedido invalido para atualizacao direta.'
            );
        }

        if ($requestedOrderType === $currentOrderType) {
            return;
        }

        if (
            $currentOrderType === self::ORDER_TYPE_SALE
            && $requestedOrderType === self::ORDER_TYPE_CART
        ) {
            throw new BadRequestHttpException(
                'Sale nao pode voltar para cart por PUT.'
            );
        }

        if (!in_array($currentOrderType, [
            self::ORDER_TYPE_CART,
            self::ORDER_TYPE_QUOTE,
            self::ORDER_TYPE_SALE,
        ], true)) {
            throw new BadRequestHttpException(
                'Tipo de pedido nao pode ser alterado por PUT.'
            );
        }

        if (in_array($this->normalizeStatusValue($order->getStatus()?->getRealStatus()), ['closed', 'canceled', 'cancelled'], true)) {
            throw new BadRequestHttpException(
                'Pedidos fechados ou cancelados nao podem trocar de tipo por PUT.'
            );
        }

        if (!in_array($requestedOrderType, [
            self::ORDER_TYPE_CART,
            self::ORDER_TYPE_SALE,
        ], true)) {
            throw new BadRequestHttpException(
                'Tipo de pedido invalido para atualizacao direta.'
            );
        }
    }

    public function securityFilter(QueryBuilder $queryBuilder, $resourceClass = null, $applyTo = null, $rootAlias = null): void
    {
        $request = $this->request;
        $companies   = $this->peopleService->getMyCompanies();
        $currentPeople = $this->peopleService->getMyPeople();
        $isClientUser = $this->currentUserHasRole('ROLE_CLIENT');

        if ($isClientUser && $currentPeople instanceof People) {
            $queryBuilder->andWhere(sprintf(
                '(%s.client = :currentPeople OR %s.payer = :currentPeople)',
                $rootAlias,
                $rootAlias,
            ));
            $queryBuilder->setParameter('currentPeople', $currentPeople);

            if ($provider = $request?->query->get('provider', null)) {
                $queryBuilder->andWhere(sprintf('%s.provider IN(:provider)', $rootAlias));
                $queryBuilder->setParameter('provider', preg_replace("/[^0-9]/", "", $provider));
            }

            if ($client = $request?->query->get('client', null)) {
                $requestedClientId = preg_replace("/[^0-9]/", "", $client);
                $currentPeopleId = (string) $currentPeople->getId();
                if ($requestedClientId !== '' && $requestedClientId !== $currentPeopleId) {
                    $queryBuilder->andWhere('1 = 0');
                }
            }

            return;
        }

        if ($companies === []) {
            $queryBuilder->andWhere('1 = 0');
            return;
        }

        if ($invoice = $request?->query->get('invoiceId', null)) {
            $queryBuilder->join(sprintf('%s.invoice', $rootAlias), 'OrderInvoice');
            $queryBuilder->andWhere(sprintf('OrderInvoice.invoice IN(:invoice)', $rootAlias, $rootAlias));
            $queryBuilder->setParameter('invoice', $invoice);
        }

        $queryBuilder->andWhere(sprintf('%s.client IN(:companies) OR %s.provider IN(:companies)', $rootAlias, $rootAlias));
        $queryBuilder->setParameter('companies', $companies);

        if ($provider = $request?->query->get('provider', null)) {
            $queryBuilder->andWhere(sprintf('%s.provider IN(:provider)', $rootAlias));
            $queryBuilder->setParameter('provider', preg_replace("/[^0-9]/", "", $provider));
        }

        if ($client = $request?->query->get('client', null)) {
            $queryBuilder->andWhere(sprintf('%s.client IN(:client)', $rootAlias));
            $queryBuilder->setParameter('client', preg_replace("/[^0-9]/", "", $client));
        }

        if ($this->isOrdersQueueRequest()) {
            $queryBuilder->andWhere(sprintf('%s.orderType = :displayOrderType', $rootAlias));
            $queryBuilder->setParameter('displayOrderType', self::ORDER_TYPE_SALE);
        }
    }

    private function currentUserHasRole(string $role): bool
    {
        $token = $this->security->getToken();
        if (!$token || !method_exists($token, 'getRoleNames')) {
            return false;
        }

        return in_array($role, $token->getRoleNames(), true);
    }

    public function postPersist(Order $order): void
    {
        $this->syncSettlementOrderPrice($order);
        $this->dispatchOrderCreated($order);
    }

    public function postUpdate(Order $order): void
    {
        $this->syncSettlementOrderPrice($order);
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

    private function queueManagerOrderPushNotifications(Order $order, People $provider): void
    {
        if (!$this->integrationService instanceof IntegrationService) {
            return;
        }

        $payload = json_encode([
            'store' => 'orders',
            'event' => 'order.created',
            'company' => (string) $provider->getId(),
            'companyId' => (string) $provider->getId(),
            'provider' => (string) $provider->getId(),
            'order' => (string) $order->getId(),
            'orderId' => (string) $order->getId(),
            'sentAt' => date(DATE_ATOM),
            'alertSound' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $this->integrationService->addManagerPushIntegrations($payload, $provider);
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

            $sentDevices[$deviceId] = $device;
        }
        $this->websocketClient->pushMany(array_values($sentDevices), $payload);
    }

    private function isPreparationOrder(Order $order): bool
    {
        return $this->normalizeStatusValue($order->getStatus()?->getRealStatus()) === 'open'
            && $this->isProductionOrder($order);
    }

    private function shouldDispatchManagerOrderPush(Order $order): bool
    {
        return $this->shouldDispatchOrderCreatedEvent($order);
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

    private function shouldDispatchOrderCreatedEvent(Order $order): bool
    {
        return $this->isProductionOrder($order)
            && (int) ($order->getId() ?? 0) > 0
            && $order->getProvider() instanceof People;
    }

    public function hasPendingFulfillment(Order $order): bool
    {
        if ($this->hasPendingDelivery($order)) {
            return true;
        }

        foreach ($order->getOrderProducts() as $orderProduct) {
            $orderProductQueues = $orderProduct->getOrderProductQueues();
            foreach ($orderProductQueues as $orderProductQueue) {
                $queueStatus = $this->normalizeStatusValue($orderProductQueue->getStatus()?->getRealStatus());
                if (!in_array($queueStatus, ['closed', 'canceled', 'cancelled'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasPendingDelivery(Order $order): bool
    {
        return $order->getAddressDestination() !== null
            || $order->getDeliveryPeople() !== null;
    }

    private function resolveImmediateMainOrder(Order $order): ?Order
    {
        $mainOrder = $order->getMainOrder();
        if ($mainOrder instanceof Order) {
            return $mainOrder;
        }

        if (!$order->getMainOrderId()) {
            return null;
        }

        return $this->findOrderById((int) $order->getMainOrderId());
    }

    private function refreshSettlementOrderPrice(Order $order): void
    {
        if (!$this->isSettlementOrder($order) || !$order->getId()) {
            return;
        }

        $total = TabConsumptionPriceCalculator::supports($order)
            ? (new TabConsumptionPriceCalculator($this->manager->getConnection()))->calculate($order)
            : (float) $this->manager->getConnection()->fetchOne(
            'SELECT IFNULL(SUM(price), 0) FROM orders WHERE main_order_id = :order_id',
            ['order_id' => (int) $order->getId()]
        );

        $order->setPrice($total);
        $this->manager->persist($order);
        $this->manager->flush();
    }

    private function updateTabConsumptionPrice(Order $order): void
    {
        if (TabConsumptionPriceCalculator::supports($order) && $order->getId()) {
            $order->setPrice((new TabConsumptionPriceCalculator($this->manager->getConnection()))->calculate($order));
        }
    }
}
