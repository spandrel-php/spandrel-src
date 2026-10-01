# Demo Architecture

`Domain` and `Infrastructure` are constrained only through the groups containing them.

## Layers

- **Domain**: `App\Domain\**`
- **Infrastructure**: `App\Infrastructure\**`
- **Shared**: `App\Shared\**`
- **Core** groups `Domain` and `Infrastructure`
- **Inner** groups `Domain`
- **Outer** groups `Infrastructure`

## Rules

- `Core` may only depend on `Core` and `Shared`
- `Inner` must not depend on `Outer`
- `Shared` may depend on anything
