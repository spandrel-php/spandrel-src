# Demo Architecture

## Layers

- **Domain**: `App\Domain\**`
- **Util**: `App\Util\**`

## Rules

- `Domain` must not call functions defined in `Util` or core functions
- `Domain` must not instantiate objects
- `Domain` may only throw `Util` or core classes
