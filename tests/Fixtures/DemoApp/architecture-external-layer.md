# Demo Architecture

## Layers

- **Domain**: `App\Domain\**`
- **SymfonyConsole** matches `Symfony\Component\Console\**`

## Rules

- `Domain` must not depend on `SymfonyConsole`
