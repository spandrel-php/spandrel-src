# Demo Architecture

## Layers

- **Domain**: `App\Domain\**`
- **Infrastructure**: `App\Infrastructure\**`
- **Shared**: `App\Shared\**`

## Rules

- `Domain` depends on nothing
- `Infrastructure` may only depend on `Domain`
- `Shared` may depend on anything
