<?php

/*
 * Contract imported from AGENTS.md
 * ## Escopo
 * - Modulo central de pedidos de venda.
 * - Cobre `Order`, `OrderProduct`, `OrderInvoice`, carrinho, acoes do pedido, descoberta de carrinho e fluxos de impressao ligados ao pedido.
 *
 * ## Quando usar
 * - Prompts sobre pedido, item de pedido, checkout operacional, impressao de pedido, acoes de pedido e ciclo de vida comercial do pedido.
 *
 * ## Limites
 * - `orders` e o dono da regra operacional do pedido.
 * - `financial` continua dono de `Invoice`, `Wallet` e meios de pagamento.
 * - `integration` continua dono de webhooks e gateways externos.
 * - `extra_data` e `extra_fields` nao podem guardar snapshot rico de pedido, entrega, pagamento ou status operacional quando o dado ja tiver destino canonico em `Order`, `OrderInvoice` ou `Invoice`. Nesta camada, esses campos so podem carregar IDs e codigos remotos que ainda nao tenham coluna materializada equivalente.
 * - Quando um fluxo tocar pedido e pagamento, a regra do pedido fica aqui e a camada financeira/integracao fica nos modulos correspondentes.
 * - Em venda, o rascunho/carrinho canonico do pedido usa `orderType = cart`. `quote` nao deve mais representar carrinho de venda.
 * - A confirmacao de pedido do `SHOP` deve recusar carrinho sem `addressDestination`; o carrinho pode existir como rascunho, mas nao pode virar venda sem endereco de entrega.
 * - Em atendimento por `tab/table`, o pedido financeiro raiz continua sendo um `Order` do proprio modulo `orders`. Pedidos filhos e invoices devem convergir para essa raiz, sem contrato paralelo fora de `mainOrderId` e `OrderInvoice`.
 * - O serializer de leitura de `Order` deve expor `mainOrder.externalCode` nos groups usados por listagem e detalhe. Esse valor e o numero da comanda e nao deve depender de `otherInformations` no frontend.
 * - `ready`, `cancel` e `delivered` devem nascer pelo fluxo principal de acoes do pedido (`OrderActionService`/`OrderActionController`). Nao criar caminhos paralelos de mudanca de status para KDS, marketplace ou device.
 * - O nome canonico da integracao da 99 no backend e `Food99` quando o pedido ou contexto precisar identificar a plataforma.
 * - O recurso `/orders-queue`, consumido por displays/KDS, deve expor apenas pedidos de venda (`orderType = sale`). Rascunhos e carrinhos (`cart`) nao pertencem a essa visao operacional.
 * - O recurso `/orders-queue` pode expor a arvore visual de componentes via group dedicado `orders-queue-tree:read`. Esse group nao deve incluir backrefs ciclicos como `orderProduct`.
 * - `orders` e `tv` continuam consumindo a arvore completa de `OrderProduct`; o `showInParentQueue` so decide a hierarquia visual do consumidor, nao a existencia do item na colecao.
 * - A visao operacional nao deve sintetizar filhos nem regravar fila para simular ocultacao visual no pai.
 * - Na impressao do pedido, `ProductGroup.showInDisplay=false` deve ocultar apenas o titulo do grupo. Os itens e componentes continuam sendo impressos e agrupados.
 * - A impressao em papel das filas deve espelhar o display correspondente: itens materializados nao devem exibir `2x`, enquanto itens internos nao materializados so podem exibir prefixo de quantidade acima de 1.
 * - Fidelidade do shop usa um pedido raiz `orderType = fidelity`. Pedidos `sale` pagos entram como filhos por `mainOrderId`; quando o cartao atinge a meta, o backend reserva o brinde no proximo `cart` do mesmo cliente/loja com item de preco zero e fecha o cartao quando esse pedido e pago.
 * - A colecao de `OrderProduct` precisa responder no payload padrao interno (`member`, `totalItems`, `search`, `@context`, `@id`, `@type`) mesmo quando a leitura vier do fluxo padrao da API Platform. Nao empurrar fallback de formato para o frontend.
 * - `OrderProduct` deve continuar exposto como entidade da API Platform. Nao usar controller dedicada apenas para reformatar colecao; essa adaptacao pertence a normalizers/infra comum.
 * - `GET /orders/{id}` deve continuar estavel e enxuto para abrir o detalhe do pedido. Nao expandir nesse payload relacoes de agrupamento (`orderProduct`, `parentProduct`, `productGroup`) se isso aumentar risco de serializacao pesada ou ciclica.
 * - Consultas agregadas de report e TV devem nascer no `OrderRepository`, nao em services. `OrderReportSummaryResolver` so orquestra o retorno e pode expor blocos extras como `operationalInsights`, mas as queries continuam no dominio de `orders`.
 * - Quando a TV pedir um `insight` especifico, `OrderReportSummaryResolver` deve devolver apenas o bloco solicitado dentro de `operationalInsights`, mantendo o contrato completo apenas para as telas que pedirem o summary inteiro.
 * - `delivery_people_id` e o campo canônico do pedido para o entregador escolhido pela loja e deve ser mantido pela propria regra de `orders`.
 * - A visao de delivery usa a colecao padrao `/orders` com `provider` do motoboy logado e `orderType=delivery`, preservando o recorte por `people_link` de tipo `courier`.
 * - Em pedidos filhos de logistica `Food99`, `provider` e o motoboy, `payer` e `99 Food`, `client` e a empresa do pedido pai, `deliveryContact` e o cliente do pedido pai, `addressOrigin` deve ser preenchido sempre e o filho nao deve copiar `otherInformations`.
 * - Quando a hierarquia completa de customizacao for necessaria no frontend, a fonte rica deve ser a colecao de `OrderProduct`, mantendo o serializer de `Order` seguro e previsivel.
 * - Em `/order_invoices`, dados expandidos de `Invoice` para o frontend devem usar um group dedicado e minimo, separado de `invoice:read`. Nao reutilizar o serializer completo de `Invoice` nessa colecao, para evitar joins excessivos e erro `1116 Too many tables`.
 * - Em `PUT /order_products/{id}`, quando vier `sub_products`, o backend deve substituir a colecao atual de componentes do item pai. Nao acumular filhos antigos com novos durante a reabertura da customizacao.
 * - Em `DELETE /order_products/{id}`, a remocao de item customizavel deve apagar primeiro a arvore de componentes e filas pelo vinculo `orderProduct`. Nao usar `parentProduct` para decidir quais filhos remover.
 * - Listagens de `Order` consumidas por `DefaultTable` React precisam de `CustomOrFilter`, `OrderFilter` e `DateFilter` alinhados ao store, com ordenacao de datas pelo valor persistido no backend.
 * - Dados agregados para mapa operacional de entregas devem sair do endpoint unico `/orders-delivery-map`, mantendo no backend a regra: status `way`/`away` sem corte diario e `closed` limitado aos 10 pedidos fechados mais recentes, sem filtro de data.
 */


namespace ControleOnline\Service;

use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\DisplayQueue;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupParent;
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


class OrderService
{
    public const ORDER_TYPE_CART = Order::ORDER_TYPE_CART;
    public const ORDER_TYPE_QUOTE = Order::ORDER_TYPE_QUOTE;
    public const ORDER_TYPE_DELIVERY = Order::ORDER_TYPE_DELIVERY;
    public const ORDER_TYPE_SALE = Order::ORDER_TYPE_SALE;
    public const ORDER_TYPE_TAB = Order::ORDER_TYPE_TAB;
    public const ORDER_TYPE_TABLE = Order::ORDER_TYPE_TABLE;
    public const ORDER_TYPE_FIDELITY = Order::ORDER_TYPE_FIDELITY;

    private const DRAFT_ORDER_APPS = [
        'pos',
        'shop',
    ];
    private const SETTLEMENT_ORDER_TYPES = [
        self::ORDER_TYPE_TAB,
        self::ORDER_TYPE_TABLE,
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
        private MessageBusInterface $bus,
        RequestStack $requestStack,
        private ?IntegrationService $integrationService = null
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
        $this->syncSettlementOrderPrice($order);

        return $order;
    }

    public function calculateGroupProductPrice(Order $order)
    {
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

    public function normalizeOrderProductGroupLinks(Order $order): bool
    {
        $orderProducts = $order->getOrderProducts()->toArray();
        usort(
            $orderProducts,
            static fn (OrderProduct $left, OrderProduct $right): int =>
                ((int) $left->getId()) <=> ((int) $right->getId())
        );

        $changed = false;

        foreach ($orderProducts as $childOrderProduct) {
            $childProduct = $childOrderProduct->getProduct();
            if (!$childProduct instanceof Product) {
                continue;
            }

            $parentOrderProduct = $childOrderProduct->getOrderProduct();
            if (!$parentOrderProduct instanceof OrderProduct) {
                $parentOrderProduct = $this->findNearestConfiguredParentOrderProduct(
                    $childOrderProduct,
                    $orderProducts,
                );
            }

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

    /**
     * Marketplace payloads can arrive as a flat list. Use the nearest previous
     * configured parent in the same order to rebuild the operational hierarchy.
     */
    private function findNearestConfiguredParentOrderProduct(
        OrderProduct $childOrderProduct,
        array $orderProducts,
    ): ?OrderProduct {
        $childId = (int) $childOrderProduct->getId();
        $childProduct = $childOrderProduct->getProduct();

        if (!$childProduct instanceof Product) {
            return null;
        }

        $candidates = array_values(array_filter(
            $orderProducts,
            fn (OrderProduct $candidate): bool =>
                (int) $candidate->getId() < $childId &&
                $candidate->getProduct() instanceof Product &&
                $this->findProductGroupProductLink($candidate->getProduct(), $childProduct) instanceof ProductGroupProduct
        ));

        if (empty($candidates)) {
            return null;
        }

        return $candidates[array_key_last($candidates)];
    }

    private function findProductGroupProductLink(
        ?Product $parentProduct,
        Product $childProduct,
        ?ProductGroup $currentProductGroup = null,
    ): ?ProductGroupProduct {
        if (!$parentProduct instanceof Product) {
            return null;
        }

        $repository = $this->manager->getRepository(ProductGroupProduct::class);
        // Prefer the hidden queue mapping when the same child exists in more than one group.
        $directLinkCandidates = $repository->createQueryBuilder('groupProduct')
            ->andWhere('groupProduct.product = :parentProduct')
            ->andWhere('groupProduct.productChild = :childProduct')
            ->andWhere('groupProduct.active = true')
            ->setParameter('parentProduct', $parentProduct)
            ->setParameter('childProduct', $childProduct)
            ->getQuery()
            ->getResult();

        $groupLinkCandidates = $repository->createQueryBuilder('groupProduct')
            ->innerJoin(
                ProductGroupParent::class,
                'groupParent',
                'WITH',
                'groupParent.productGroup = groupProduct.productGroup'
            )
            ->andWhere('groupProduct.productChild = :childProduct')
            ->andWhere('groupProduct.active = true')
            ->andWhere('groupParent.parentProduct = :parentProduct')
            ->andWhere('groupParent.active = true')
            ->setParameter('childProduct', $childProduct)
            ->setParameter('parentProduct', $parentProduct)
            ->getQuery()
            ->getResult();

        return $this->pickPreferredProductGroupProductLink(
            array_merge($directLinkCandidates, $groupLinkCandidates),
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

    public function isSettlementOrderType(?string $orderType): bool
    {
        return in_array(
            $this->normalizeStatusValue($orderType),
            self::SETTLEMENT_ORDER_TYPES,
            true
        );
    }

    public function isSettlementOrder(Order $order): bool
    {
        return $this->isSettlementOrderType($order->getOrderType());
    }

    public function resolveFinancialOrder(Order $order): Order
    {
        $resolvedOrder = $order;
        $visitedOrderIds = [];

        while ($resolvedOrder->getMainOrderId()) {
            $resolvedOrderId = $resolvedOrder->getId();
            if ($resolvedOrderId && isset($visitedOrderIds[$resolvedOrderId])) {
                break;
            }

            if ($resolvedOrderId) {
                $visitedOrderIds[$resolvedOrderId] = true;
            }

            $nextOrder = $resolvedOrder->getMainOrder();
            if (!$nextOrder instanceof Order) {
                $nextOrder = $this->findOrderById((int) $resolvedOrder->getMainOrderId());
            }

            if (!$nextOrder instanceof Order) {
                break;
            }

            $resolvedOrder = $nextOrder;
        }

        return $this->isSettlementOrder($resolvedOrder) ? $resolvedOrder : $order;
    }

    public function syncSettlementOrderPrice(Order $order): void
    {
        $settlementOrder = $this->isSettlementOrder($order)
            ? $order
            : $this->resolveImmediateMainOrder($order);
        $visitedOrderIds = [];

        while ($settlementOrder instanceof Order) {
            $settlementOrderId = $settlementOrder->getId();
            if (!$settlementOrderId || isset($visitedOrderIds[$settlementOrderId])) {
                break;
            }

            $visitedOrderIds[$settlementOrderId] = true;

            if ($this->isSettlementOrder($settlementOrder)) {
                $this->refreshSettlementOrderPrice($settlementOrder);
            }

            $settlementOrder = $this->resolveImmediateMainOrder($settlementOrder);
        }
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

    public function resolvePostPaymentStatus(Order $order): Status
    {
        // Payment finalization always operates on a sale order, never on a draft cart.
        if ($this->normalizeStatusValue($order->getOrderType()) !== self::ORDER_TYPE_SALE) {
            $this->convertDraftOrderToSale($order);
        }

        $currentRealStatus = $this->normalizeStatusValue($order->getStatus()?->getRealStatus());
        if (in_array($currentRealStatus, ['closed', 'canceled', 'cancelled'], true)) {
            return $this->statusService->discoveryStatus('closed', 'closed', 'order');
        }

        // Payment only closes the order when nothing else is waiting to be prepared or delivered.
        if ($this->hasPendingFulfillment($order)) {
            return $this->statusService->discoveryStatus('open', 'preparing', 'order');
        }

        return $this->statusService->discoveryStatus('closed', 'closed', 'order');
    }

    public function dispatchOrderCreated(Order $order): void
    {
        if (!$this->shouldDispatchOrderCreatedEvent($order)) {
            return;
        }

        $provider = $order->getProvider();
        if (!$provider instanceof People) {
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

        // Only sale orders represent a real order; cart stays silent until it is promoted.
        if ($this->shouldDispatchManagerOrderPush($order)) {
            $this->queueManagerOrderPushNotifications($order, $provider);
        }

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
        $request = $this->request;
        $companies   = $this->peopleService->getMyCompanies();

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

            $sentDevices[$deviceId] = true;
            $this->websocketClient->push($device, $payload);
        }
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

    private function hasPendingFulfillment(Order $order): bool
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

        $total = (float) $this->manager->getConnection()->fetchOne(
            'SELECT IFNULL(SUM(price), 0) FROM orders WHERE main_order_id = :order_id',
            ['order_id' => (int) $order->getId()]
        );

        $order->setPrice($total);
        $this->manager->persist($order);
        $this->manager->flush();
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
