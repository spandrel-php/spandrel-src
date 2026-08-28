# Demo Architecture

## Layers

- **Domain**: `App\Domain\**`

## Rules

- `Domain` must not depend on `App\Vendor\**`
- `App\Legacy\**` may only depend on `Domain` except `App\Legacy\Excluded`
