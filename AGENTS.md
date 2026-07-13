## Orders
- Keep this module focused on order-domain ownership, lifecycle boundaries, and thin controller orchestration.
- Keep business-specific decisions close to the implementation in English code comments, and use `@agents` only on the first line of each rule comment block; use AGENTS for reusable patterns and operating modes only.
- Keep tests under this module and update the root AGENTS when a rule truly spans modules.
