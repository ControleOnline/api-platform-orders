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
