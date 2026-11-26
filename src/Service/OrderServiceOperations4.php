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
trait OrderServiceOperations4
{
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

    private function normalizeOrderTypeValue(mixed $value): string
    {
        if (is_object($value) && method_exists($value, 'getId')) {
            $value = $value->getId();
        }

        if (is_array($value)) {
            $value = $value['@id'] ?? $value['id'] ?? $value['orderType'] ?? null;
        }

        return $this->normalizeStatusValue($value);
    }

    private function resolvePayloadEntityId(mixed $value): ?int
    {
        if (is_object($value) && method_exists($value, 'getId')) {
            $value = $value->getId();
        }

        if (is_array($value)) {
            $value = $value['@id'] ?? $value['id'] ?? null;
        }

        $normalized = preg_replace('/\D+/', '', (string) $value);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        return (int) $normalized;
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

    private function clearAnonymousCartExternalCode(Order $order): void
    {
        $externalCode = (string) ($order->getExternalCode() ?? '');

        if (str_starts_with($externalCode, self::ANONYMOUS_CART_EXTERNAL_CODE_PREFIX)) {
            $order->setExternalCode(null);
        }
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
