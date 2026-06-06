## Orders
- A relacao de anexos do pedido e `order_file`; cada par `order + file` deve permanecer unico.
- O detalhe do pedido precisa serializar `orderFiles` e os metadados de `File` com suporte a `order_file:read`.
- O contexto de upload usado pela biblioteca do pedido e `order-attachments`.
- Nao usar fluxo de produto para gravar, remover ou listar anexos de pedido.
