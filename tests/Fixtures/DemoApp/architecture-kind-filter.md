# Demo Architecture

## Layers

- **Domain**: `App\Domain\**`

## Rules

- `Domain` may only depend on interfaces in `Symfony\**`
- classes and enums in `App\Legacy\**` must not depend on `Domain`
