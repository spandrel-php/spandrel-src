# Demo Architecture

Modules declared before their code exists. `Reporting` has no rule yet.

## Layers

- `App\{Module}\{Layer}\**`
  - with Modules `Billing`, `Shipping`, and `Reporting`
  - with Layers `Domain` and `Infrastructure`

## Rules

- `Domain` must not depend on `Infrastructure`
- `Billing` may only depend on `Billing`
- `Shipping` may only depend on `Shipping` and `Billing`
