## Ponto de entrada

- A documentação funcional e de regras deste modulo vive na wiki do proprio repositório e na wiki principal da API.
- Regras transversais de qualidade, modularizacao e limites de componente vivem em `https://github.com/ControleOnline/agents-mcp/blob/master/skills/shared/code-quality.md`.
- Quando houver detalhe especifico de implementacao, prefira comentar no codigo em ingles perto da regra.
- Este arquivo deve ficar curto e servir apenas como ponte para as fontes oficiais.

## Write authorization on order product mutations

- `PUT /orders/{id}/add-products` and `PUT /orders/{id}/replace-products` must resolve the order via `OrderService::findAccessibleOrderById` (provider ownership / `PeopleService::canAccessCompany`). Do not use bare `findOrderById` on these write paths.
- Proposal product category is enforced server-side by `ProposalProductCategoryGuard` on `OrderProductService` prePersist/preUpdate when the order's contract model has a category.
