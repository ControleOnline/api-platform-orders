[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/controleonline/api-platform-orders/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/controleonline/api-platform-orders/?branch=master)

# orders

`composer require controleonline/orders:dev-master`

Add Service import:
config\services.yaml

```yaml
imports:
    - { resource: "../modules/controleonline/orders/financial/services/orders.yaml" }
```

## Proposal Product Category Guard

When an order belongs to a proposal whose model has a category, the API now enforces that top-level proposal products match that same category.

Behavior summary:
- `/orders/{id}/add-products` rejects products outside the proposal model category
- direct `OrderProduct` mutations apply the same rule for top-level proposal products
- nested customization subproducts are not blocked by this guard
- when the proposal model has no category, the previous permissive behavior is preserved

Validation:
- focused PHPUnit coverage lives in `tests/Service/ProposalProductCategoryGuardTest.php`
- GitHub Actions workflow `Pull Request Checks` publishes the automated evidence for this rule
