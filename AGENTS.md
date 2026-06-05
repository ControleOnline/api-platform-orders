## Orders
- A relacao de anexos do pedido e `order_file`; cada par `order + file` deve permanecer unico.
- O detalhe do pedido precisa serializar `orderFiles` e os metadados de `File` com suporte a `order_file:read`.
- O contexto de upload usado pela biblioteca do pedido e `order-attachments`.
- Nao usar fluxo de produto para gravar, remover ou listar anexos de pedido.

## Contrato de pedidos
- `Order.orderProducts` deve serializar apenas itens raiz nos contratos de leitura `orders-queue:read` e `order_details:read`.
- Filhos, modificadores e complementos devem aparecer somente em `OrderProduct.orderProductComponents`, preservando a arvore real.
- A colecao interna `Order.getOrderProducts()` continua representando todos os itens persistidos do pedido, incluindo filhos.
- `order_product_queues` aponta para o `OrderProduct` real que entrou na producao, seja raiz ou filho; nao criar fila sintetica para regra visual.
- KDS/TV e detalhes devem consumir a arvore materializada e nao fazer deduplicacao visual.

## Testes
- Testes automatizados deste modulo ficam em `tests/`.
- Ao alterar serializacao ou hierarquia de `OrderProduct`, cobrir o contrato raiz/filhos antes de concluir.
