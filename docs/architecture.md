# Spandrel Architecture

Dogfooding ruleset: Spandrel analysing its own codebase. Reflects the
actual current dependency structure under `Spandrel\Spandrel\` — 11
namespaces, `Version` the newest, holding the git tag/commit `box
compile` bakes into a release build (see [box.json](../box.json)) and
what the SARIF reporter's `tool.driver.version` reports.

Layers are declared via the `{Layer}` placeholder form with its values
listed — see
[docs/ruleset.md](ruleset.md#listing-values-before-the-code-exists) —
so each namespace directly under `Spandrel\Spandrel\` is a layer
without a bullet of its own. The list is closed: a new namespace stays
unmatched, and fails analysis, until it's added here along with a rule.

## Meta

- Any class not in a layer violates rules.
- A file that fails to parse violates rules.
- Every layer must be used in a rule.

## Layers

- `Spandrel\Spandrel\{Layer}\**`
  - with Layers `Baseline`, `Cache`, `Config`, `Console`, `Graph`, `Loader`, `Parser`, `Reporting`, `RuleEngine`, `Ruleset`, and `Version`

- **IO** groups `Config`, `Loader`, `Cache`, `Reporting`, `Console`, `Baseline`, and `Version`
- **Core** groups `Graph`, `Ruleset`, `Parser`, and `RuleEngine`

`SymfonyConsole` below is a live reference example of an [external
layer](ruleset.md#external-layers): a named vendor
pattern the rule below can enforce against, with nothing added to
`source.paths` in `spandrel.yaml` — Spandrel still parses only `src`.

- **SymfonyConsole** matches `Symfony\Component\Console\**`

## Rules

- `Core` must not depend on `IO`
- `IO` may depend on anything
- `Core` must not depend on `SymfonyConsole`

- `Config` depends on nothing
- `Graph` depends on nothing
- `Loader` depends on nothing
- `Version` depends on nothing

- `Parser` may only depend on `Graph`
- `Ruleset` may only depend on `Graph`
- `RuleEngine` may only depend on `Graph` and `Ruleset`
- `Cache` may only depend on `Graph`
- `Reporting` may only depend on `RuleEngine`, `Graph`, `Ruleset`, and `Version`
- `Baseline` may only depend on `RuleEngine`

## Diagram

Generated via `analyse --report=mermaid`; a snapshot, not kept in sync
automatically — regenerate with:

```sh
docker compose run --rm php php bin/spandrel.php analyse src --ruleset=docs/architecture.md --report=mermaid
```

```mermaid
flowchart LR
    subgraph IO
        Config
        Loader
        Cache
        Reporting
        Console
        Baseline
        Version
    end
    subgraph Core
        Graph
        Ruleset
        Parser
        RuleEngine
    end
    SymfonyConsole

    Baseline -->|"2"| RuleEngine
    Cache -->|"2"| Graph
    Console -->|"3"| Baseline
    Console -->|"7"| Cache
    Console -->|"10"| Config
    Console -->|"2"| Graph
    Console -->|"1"| Loader
    Console -->|"2"| Parser
    Console -->|"9"| Reporting
    Console -->|"1"| RuleEngine
    Console -->|"14"| Ruleset
    Parser -->|"22"| Graph
    Reporting -->|"3"| Graph
    Reporting -->|"8"| RuleEngine
    Reporting -->|"7"| Ruleset
    Reporting -->|"1"| Version
    RuleEngine -->|"15"| Graph
    RuleEngine -->|"35"| Ruleset
    Ruleset -->|"17"| Graph
```
