## Orders
- A relacao de anexos do pedido e `order_file`; cada par `order + file` deve permanecer unico.
- O detalhe do pedido precisa serializar `orderFiles` e os metadados de `File` com suporte a `order_file:read`.
- O contexto de upload usado pela biblioteca do pedido e `order-attachments`.
- Nao usar fluxo de produto para gravar, remover ou listar anexos de pedido.
- O endpoint `PUT /orders/{id}/replace-products` e o contrato canonico para o modo `single-item` do POS; ele deve substituir os produtos raiz do pedido, recalcular totais e deixar apenas um item principal por vez.
