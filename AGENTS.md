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
- `ready`, `cancel` e `delivered` devem nascer pelo fluxo principal de acoes do pedido (`OrderActionService`/`OrderActionController`). Nao criar caminhos paralelos de mudanca de status para KDS, marketplace ou device.
- O nome canonico da integracao da 99 no backend e `Food99` quando o pedido ou contexto precisar identificar a plataforma.
- O recurso `/orders-queue`, consumido por displays/KDS, deve expor apenas pedidos de venda (`orderType = sale`). Rascunhos e carrinhos (`cart`) nao pertencem a essa visao operacional.
- A colecao de `OrderProduct` precisa responder no payload padrao interno (`member`, `totalItems`, `search`, `@context`, `@id`, `@type`) mesmo quando a leitura vier do fluxo padrao da API Platform. Nao empurrar fallback de formato para o frontend.
- `OrderProduct` deve continuar exposto como entidade da API Platform. Nao usar controller dedicada apenas para reformatar colecao; essa adaptacao pertence a normalizers/infra comum.
- `GET /orders/{id}` deve continuar estavel e enxuto para abrir o detalhe do pedido. Nao expandir nesse payload relacoes de agrupamento (`orderProduct`, `parentProduct`, `productGroup`) se isso aumentar risco de serializacao pesada ou ciclica.
- Quando a hierarquia completa de customizacao for necessaria no frontend, a fonte rica deve ser a colecao de `OrderProduct`, mantendo o serializer de `Order` seguro e previsivel.
