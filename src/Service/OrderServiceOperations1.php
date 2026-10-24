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
trait OrderServiceOperations1
{
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
        $this->syncSettlementOrderPrice($order);

        return $order;
    }

    public function calculateGroupProductPrice(Order $order)
    {
        $linkCandidates = new OrderProductGroupLinkCandidates($this->manager);
        foreach ($order->getOrderProducts() as $rootOrderProduct) {
            if (
                !$rootOrderProduct instanceof OrderProduct
                || $rootOrderProduct->getOrderProduct() instanceof OrderProduct
                || trim((string) $rootOrderProduct->getComment()) === OrderProductService::LOYALTY_GIFT_COMMENT
            ) {
                continue;
            }

            $this->treePriceCalculator->recalculateComponents(
                $rootOrderProduct,
                function (OrderProduct $component) use ($linkCandidates): float {
                    $parent = $component->getOrderProduct();
                    $product = $component->getProduct();
                    if (
                        !$parent instanceof OrderProduct
                        || !$product instanceof Product
                    ) {
                        return (float) $component->getPrice();
                    }

                    $groupProduct = $this->findProductGroupProductLink(
                        $parent->getProduct(),
                        $product,
                        $component->getProductGroup(),
                        $linkCandidates,
                    );

                    return $groupProduct instanceof ProductGroupProduct
                        ? (float) $groupProduct->getPrice()
                        : (float) $component->getPrice();
                },
            );
        }
        $this->manager->flush();

        $sql = 'UPDATE order_product OPO
                INNER JOIN product P ON P.id = OPO.product_id
                LEFT JOIN (
                    SELECT grouped_prices.order_product_id, SUM(grouped_prices.group_price) AS extra_price
                    FROM (
                        SELECT
                            OP.order_product_id,
                            OP.product_group_id,
                            CASE
                                WHEN PG.price_calculation = "biggest" THEN MAX(OP.price)
                                WHEN PG.price_calculation = "average" THEN AVG(OP.price)
                                WHEN PG.price_calculation = "free" THEN 0
                                ELSE SUM(OP.price)
                            END AS group_price
                        FROM order_product OP
                        INNER JOIN product_group PG ON OP.product_group_id = PG.id
                        WHERE OP.order_product_id IS NOT NULL
                            AND OP.product_group_id IS NOT NULL
                            AND OP.order_id = :order_id
                        GROUP BY OP.order_product_id, OP.product_group_id, PG.price_calculation
                    ) AS grouped_prices
                    GROUP BY grouped_prices.order_product_id
                ) AS parent_prices ON parent_prices.order_product_id = OPO.id
                SET OPO.price = P.price + IFNULL(parent_prices.extra_price, 0),
                    OPO.total = (P.price + IFNULL(parent_prices.extra_price, 0)) * OPO.quantity
                WHERE OPO.order_product_id IS NULL
                    AND OPO.order_id = :root_order_id
                    AND (
                        parent_prices.order_product_id IS NOT NULL
                        OR EXISTS (
                            SELECT 1
                            FROM product_group_parent PGPARENT
                            WHERE PGPARENT.parent_product_id = OPO.product_id
                                AND PGPARENT.active = 1
                        )
                    )
                    AND (
                        OPO.comment IS NULL
                        OR TRIM(OPO.comment) <> :loyalty_gift_comment
                    )
                ';
        $connection = $this->manager->getConnection();
        $statement = $connection->prepare($sql);
        $statement->bindValue(':order_id', $order->getId(), Type::getType('integer'));
        $statement->bindValue(':root_order_id', $order->getId(), Type::getType('integer'));
        $statement->bindValue(':loyalty_gift_comment', OrderProductService::LOYALTY_GIFT_COMMENT);
        $statement->executeStatement();

        return $order;
    }

    public function prepareOrderDetailsRead(Order $order): bool
    {
        $this->updateTabConsumptionPrice($order);
        // Reuse the same actor/device policy enforced when an invoice is written.
        if ($this->isSettlementOrderType($order->getOrderType())) {
            $order->setChargeCapability($this->commercialContextService->resolveChargeCapability($order));
        }
        (new OrderDetailsReadLoader($this->manager))->load($order);
        return $this->normalizeOrderProductGroupLinks($order);
    }

    public function normalizeOrderProductGroupLinks(Order $order): bool
    {
        $changed = false;
        $linkCandidates = new OrderProductGroupLinkCandidates($this->manager);

        foreach ($order->getOrderProducts() as $childOrderProduct) {
            $childProduct = $childOrderProduct->getProduct();
            if (!$childProduct instanceof Product) {
                continue;
            }

            $parentOrderProduct = $childOrderProduct->getOrderProduct();
            // @agents Order hierarchy is transactional data. Never infer it from catalog groups.
            if (!$parentOrderProduct instanceof OrderProduct) {
                continue;
            }

            if ((int) $parentOrderProduct->getId() === (int) $childOrderProduct->getId()) {
                continue;
            }

            $groupProduct = $this->findProductGroupProductLink(
                $parentOrderProduct->getProduct(),
                $childProduct,
                $childOrderProduct->getProductGroup(),
                $linkCandidates,
            );

            if (!$groupProduct instanceof ProductGroupProduct) {
                continue;
            }

            if ($childOrderProduct->getOrderProduct() !== $parentOrderProduct) {
                $childOrderProduct->setOrderProduct($parentOrderProduct);
                $changed = true;
            }

            if ($childOrderProduct->getParentProduct() !== $parentOrderProduct->getProduct()) {
                $childOrderProduct->setParentProduct($parentOrderProduct->getProduct());
                $changed = true;
            }

            if ($childOrderProduct->getProductGroup() !== $groupProduct->getProductGroup()) {
                $childOrderProduct->setProductGroup($groupProduct->getProductGroup());
                $changed = true;
            }

            if ($childOrderProduct->getShowInParentQueue() !== $groupProduct->getShowInParentQueue()) {
                $childOrderProduct->setShowInParentQueue($groupProduct->getShowInParentQueue());
                $changed = true;
            }
        }

        if ($changed) {
            $this->manager->flush();
            $this->manager->refresh($order);
        }

        return $changed;
    }

    private function findProductGroupProductLink(
        ?Product $parentProduct,
        Product $childProduct,
        ?ProductGroup $currentProductGroup,
        OrderProductGroupLinkCandidates $linkCandidates,
    ): ?ProductGroupProduct {
        if (!$parentProduct instanceof Product) {
            return null;
        }

        return $this->pickPreferredProductGroupProductLink(
            $linkCandidates->get($parentProduct, $childProduct),
            $currentProductGroup,
        );
    }

    /**
     * @param array<int, mixed> $productGroupProducts
     */
    private function pickPreferredProductGroupProductLink(
        array $productGroupProducts,
        ?ProductGroup $currentProductGroup = null,
    ): ?ProductGroupProduct {
        $candidates = array_values(array_filter(
            $productGroupProducts,
            static fn (mixed $candidate): bool => $candidate instanceof ProductGroupProduct,
        ));

        if (empty($candidates)) {
            return null;
        }

        usort(
            $candidates,
            function (ProductGroupProduct $left, ProductGroupProduct $right) use ($currentProductGroup): int {
                $leftVisibilityRank = $left->getShowInParentQueue() ? 1 : 0;
                $rightVisibilityRank = $right->getShowInParentQueue() ? 1 : 0;

                if ($leftVisibilityRank !== $rightVisibilityRank) {
                    return $leftVisibilityRank <=> $rightVisibilityRank;
                }

                if ($currentProductGroup instanceof ProductGroup) {
                    $leftMatchesCurrentGroup = $this->matchesProductGroup(
                        $left,
                        $currentProductGroup,
                    );
                    $rightMatchesCurrentGroup = $this->matchesProductGroup(
                        $right,
                        $currentProductGroup,
                    );

                    if ($leftMatchesCurrentGroup !== $rightMatchesCurrentGroup) {
                        return $rightMatchesCurrentGroup <=> $leftMatchesCurrentGroup;
                    }
                }

                return (int) $right->getId() <=> (int) $left->getId();
            }
        );

        return $candidates[0] ?? null;
    }

    private function matchesProductGroup(
        ProductGroupProduct $groupProduct,
        ProductGroup $currentProductGroup,
    ): bool {
        $linkedProductGroup = $groupProduct->getProductGroup();

        return $linkedProductGroup instanceof ProductGroup
            && (int) $linkedProductGroup->getId() === (int) $currentProductGroup->getId();
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
        $this->commercialContextService->prepare($order, !$startsAsCart);

        $this->manager->persist($order);
        $this->manager->flush();
        return $order;
    }

    public function findOrderById(int $orderId): ?Order
    {
        return $this->manager->getRepository(Order::class)->find($orderId);
    }

    public function updateOrderFromPayload(Order $order, array $payload): Order
    {
        $this->assertDirectOrderUpdateAllowed($order, $payload);

        $requestedOrderType = $this->normalizeOrderTypeValue($payload['orderType'] ?? null);
        $requestedOrderType = $requestedOrderType === 'quote'
            ? self::ORDER_TYPE_CART
            : $requestedOrderType;

        $sanitizedPayload = $payload;
        unset(
            $sanitizedPayload['id'],
            $sanitizedPayload['@id'],
            $sanitizedPayload['@type'],
            $sanitizedPayload['@context'],
            $sanitizedPayload['client'],
            $sanitizedPayload['payer'],
            $sanitizedPayload['status'],
            $sanitizedPayload['orderType'],
            $sanitizedPayload['orderProducts'],
        );

        if (!empty($sanitizedPayload)) {
            $this->serializer->deserialize(
                json_encode(
                    $sanitizedPayload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) ?: '{}',
                Order::class,
                'json',
                [
                    'object_to_populate' => $order,
                    'groups' => ['order:write'],
                ]
            );
        }

        if (array_key_exists('client', $payload)) {
            $order->setClient($this->resolveOrderPeopleReference($payload['client'] ?? null));
        }

        if (array_key_exists('payer', $payload)) {
            $order->setPayer($this->resolveOrderPeopleReference($payload['payer'] ?? null));
        }

        $promotedToSale = false;
        $currentOrderType = $this->normalizeStatusValue($order->getOrderType());

        if ($requestedOrderType === self::ORDER_TYPE_SALE && $currentOrderType !== self::ORDER_TYPE_SALE) {
            $promotedToSale = $this->convertDraftOrderToSale($order);
            if (!$promotedToSale) {
                throw new BadRequestHttpException(
                    'Tipo de pedido nao pode ser promovido para sale neste contexto.'
                );
            }
        } elseif ($requestedOrderType === self::ORDER_TYPE_CART && $currentOrderType !== self::ORDER_TYPE_CART) {
            if (!$this->normalizeDraftCartOrder($order)) {
                throw new BadRequestHttpException(
                    'Tipo de pedido nao pode voltar para cart neste contexto.'
                );
            }
        }

        $this->manager->persist($order);
        $this->manager->flush();

        if ($promotedToSale) {
            $this->dispatchOrderCreated($order);
        }

        return $order;
    }
}
