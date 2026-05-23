## Escopo
- Modulo central de pedidos de venda.
- Cobre `Order`, `OrderProduct`, `OrderInvoice`, carrinho, acoes do pedido, descoberta de carrinho e fluxos de impressao ligados ao pedido.

## Quando usar
- Prompts sobre pedido, item de pedido, checkout operacional, impressao de pedido, acoes de pedido e ciclo de vida comercial do pedido.

## Limites
- `orders` e o dono da regra operacional do pedido.
- `financial` continua dono de `Invoice`, `Wallet` e meios de pagamento.
- `integration` continua dono de webhooks e gateways externos.
- Quando um fluxo tocar pedido e pagamento, a regra do pedido fica aqui e a camada financeira/integracao fica nos modulos correspondentes.
- Em venda, o rascunho/carrinho canonico do pedido usa `orderType = cart`. `quote` nao deve mais representar carrinho de venda.
- Em atendimento por `tab/table`, o pedido financeiro raiz continua sendo um `Order` do proprio modulo `orders`. Pedidos filhos e invoices devem convergir para essa raiz, sem contrato paralelo fora de `mainOrderId` e `OrderInvoice`.
- `ready`, `cancel` e `delivered` devem nascer pelo fluxo principal de acoes do pedido (`OrderActionService`/`OrderActionController`). Nao criar caminhos paralelos de mudanca de status para KDS, marketplace ou device.
- O nome canonico da integracao da 99 no backend e `Food99` quando o pedido ou contexto precisar identificar a plataforma.
- O recurso `/orders-queue`, consumido por displays/KDS, deve expor apenas pedidos de venda (`orderType = sale`). Rascunhos e carrinhos (`cart`) nao pertencem a essa visao operacional.
- O recurso `/orders-queue` pode expor a arvore visual de componentes via group dedicado `orders-queue-tree:read`. Esse group nao deve incluir backrefs ciclicos como `orderProduct`.
- `orders` e `tv` continuam consumindo a arvore completa de `OrderProduct`; o `showInParentQueue` so decide a hierarquia visual do consumidor, nao a existencia do item na colecao.
- A visao operacional nao deve sintetizar filhos nem regravar fila para simular ocultacao visual no pai.
- Na impressao do pedido, `ProductGroup.showInDisplay=false` deve ocultar apenas o titulo do grupo. Os itens e componentes continuam sendo impressos e agrupados.
- A impressao em papel das filas deve espelhar o display correspondente: itens materializados nao devem exibir `2x`, enquanto itens internos nao materializados so podem exibir prefixo de quantidade acima de 1.
- Fidelidade do shop usa um pedido raiz `orderType = fidelity`. Pedidos `sale` pagos entram como filhos por `mainOrderId`; quando o cartao atinge a meta, o backend reserva o brinde no proximo `cart` do mesmo cliente/loja com item de preco zero e fecha o cartao quando esse pedido e pago.
- A colecao de `OrderProduct` precisa responder no payload padrao interno (`member`, `totalItems`, `search`, `@context`, `@id`, `@type`) mesmo quando a leitura vier do fluxo padrao da API Platform. Nao empurrar fallback de formato para o frontend.
- `OrderProduct` deve continuar exposto como entidade da API Platform. Nao usar controller dedicada apenas para reformatar colecao; essa adaptacao pertence a normalizers/infra comum.
- `GET /orders/{id}` deve continuar estavel e enxuto para abrir o detalhe do pedido. Nao expandir nesse payload relacoes de agrupamento (`orderProduct`, `parentProduct`, `productGroup`) se isso aumentar risco de serializacao pesada ou ciclica.
- `delivery_people_id` e o campo canônico do pedido para o entregador escolhido pela loja e deve ser mantido pela propria regra de `orders`.
- Em pedidos filhos de logistica `Food99`, `provider` e o motoboy, `payer` e `99 Food`, `client` e a empresa do pedido pai, `deliveryContact` e o cliente do pedido pai, `addressOrigin` deve ser preenchido sempre e o filho nao deve copiar `otherInformations`.
- Quando a hierarquia completa de customizacao for necessaria no frontend, a fonte rica deve ser a colecao de `OrderProduct`, mantendo o serializer de `Order` seguro e previsivel.
- Em `/order_invoices`, dados expandidos de `Invoice` para o frontend devem usar um group dedicado e minimo, separado de `invoice:read`. Nao reutilizar o serializer completo de `Invoice` nessa colecao, para evitar joins excessivos e erro `1116 Too many tables`.
- Em `PUT /order_products/{id}`, quando vier `sub_products`, o backend deve substituir a colecao atual de componentes do item pai. Nao acumular filhos antigos com novos durante a reabertura da customizacao.
- Em `DELETE /order_products/{id}`, a remocao de item customizavel deve apagar primeiro a arvore de componentes e filas pelo vinculo `orderProduct`. Nao usar `parentProduct` para decidir quais filhos remover.
- Listagens de `Order` consumidas por `DefaultTable` React precisam de `CustomOrFilter`, `OrderFilter` e `DateFilter` alinhados ao store, com ordenacao de datas pelo valor persistido no backend.
- Dados agregados para mapa operacional de entregas devem sair do endpoint unico `/orders-delivery-map`, mantendo no backend a regra: status `way`/`away` sem corte diario e `closed` limitado aos 10 pedidos fechados mais recentes, sem filtro de data.
