## Orders
- A relacao de anexos do pedido e `order_file`; cada par `order + file` deve permanecer unico.
- O detalhe do pedido precisa serializar `orderFiles` e os metadados de `File` com suporte a `order_file:read`.
- O contexto de upload usado pela biblioteca do pedido e `order-attachments`.
- Nao usar fluxo de produto para gravar, remover ou listar anexos de pedido.
- A visao operacional de `orders` e `tv` usa `/orders` com `order:read` e precisa da arvore completa de `orderProducts`, incluindo `productGroup`, `orderProductComponents` e `orderProductQueues`.
- A impressao da fila deve usar `order_product_queue.id` como codigo de barras para conferencia, mas a marcacao final continua acontecendo apenas em `order_product`.
- Conferencia por fila e conferencia por SKU compartilham o mesmo contrato de status do `order_product`; nao criar persistencia adicional de conferido em fila no curto prazo.
