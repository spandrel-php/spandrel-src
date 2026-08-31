# Report Formats

`analyse` reports violations in one of four formats — `console`
(default), `json`, `sarif`, or `mermaid` — selected with
`--report=FORMAT[:OUTPUT]`, repeatable to emit several in one run. This
page covers what each format actually contains; see
[docs/cli.md](cli.md#analyse-alias-analyze) for the `--report`/
`--diagram-*` options themselves, and how `--baseline` suppresses
already-known violations from all of them.

## Two reporter shapes

```
Reporter {
    format(violations: Violation[]): string
}

GraphReporter {
    format(violations: Violation[], graph: CodeGraph, ruleset: Ruleset): string
}
```

Console, JSON, and SARIF report on individual violations alone — they
never read the Code Graph or the Ruleset, only strings already baked
into each `Violation` — so they implement the narrower `Reporter`.
Mermaid needs the actual layers (to know the graph's nodes and
groupings) and every observed dependency, not just the violating ones,
so it implements `GraphReporter` instead. The two are sibling
interfaces rather than one extending the other — PHP requires an
overriding method's added parameters to be optional, and there's no
sane default for `Ruleset` to fall back to — so `analyse` dispatches on
which interface a reporter actually implements.

## Console

The default, human-facing format. Groups violations by file, colorizes
the offending line, and prints a summary count:

```
$ spandrel analyse src
App/Domain/Foo.php
  Line 4: App\Domain\Foo (Domain) must not depend on App\Infrastructure\Db (Infrastructure) via param-type

1 violation across 1 file
```

`-v`/`--verbose` additionally prints the matched rule's own text under
each violation:

```
$ spandrel analyse src -v
App/Domain/Foo.php
  Line 4: App\Domain\Foo (Domain) must not depend on App\Infrastructure\Db (Infrastructure) via param-type
    Rule: `Domain` must not depend on `Infrastructure`

1 violation across 1 file
```

With no violations, or once a baseline suppresses all of them, the
summary line is replaced entirely:

```
No violations found.
No new violations found (1 baselined).
```

## JSON

Machine-readable, stable schema, one array entry per `Violation` — no
use for the Code Graph or Ruleset, same as Console:

```json
{
    "violations": [
        {
            "rule": "`Domain` must not depend on `Infrastructure`",
            "from": "App\\Domain\\Foo",
            "to": "App\\Infrastructure\\Db",
            "kind": "param-type",
            "file": "App/Domain/Foo.php",
            "line": 4,
            "message": "App\\Domain\\Foo (Domain) must not depend on App\\Infrastructure\\Db (Infrastructure) via param-type"
        }
    ]
}
```

`kind` is the `Dependency` edge kind that triggered the violation
(`extends`, `implements`, `use-trait`, `param-type`, `property-type`,
`return-type`, `new`, `static-call`, `instanceof`, `catch`, `call`,
`throw`, `attribute`) — the same vocabulary a kind-scoped rule matches
against. No violations still produces valid JSON:
`{"violations": []}`, never an empty string.

## SARIF

[SARIF 2.1.0](https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html)
for CI code-scanning integrations (GitHub code scanning annotations
pointing directly at the violating line, for example):

```json
{
    "$schema": "https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json",
    "version": "2.1.0",
    "runs": [
        {
            "tool": {
                "driver": {
                    "name": "Spandrel",
                    "version": "dev",
                    "rules": [
                        {
                            "id": "7f7cb2379228",
                            "shortDescription": { "text": "`Domain` must not depend on `Infrastructure`" }
                        }
                    ]
                }
            },
            "results": [
                {
                    "ruleId": "7f7cb2379228",
                    "level": "error",
                    "message": { "text": "App\\Domain\\Foo (Domain) must not depend on App\\Infrastructure\\Db (Infrastructure) via param-type" },
                    "locations": [
                        {
                            "physicalLocation": {
                                "artifactLocation": { "uri": "App/Domain/Foo.php" },
                                "region": { "startLine": 4, "startColumn": 31, "endLine": 4, "endColumn": 35 }
                            }
                        }
                    ]
                }
            ]
        }
    ]
}
```

- `tool.driver.version` is `Spandrel\Spandrel\Version\Version::current()`
  — the git tag/commit `box compile` baked into the running phar (see
  [box.json](../box.json)), or the fixed string `"dev"` when running
  from source (`php bin/spandrel.php`) rather than a released build.
- `tool.driver.rules[]` is built from the **distinct rule-text strings
  actually present in the violations**, not from every rule bullet in
  the ruleset — a ruleset with a hundred rules but one kind of
  violation gets one SARIF rule object. `id` is a 12-character xxh3
  hash of that rule text; `shortDescription` is the rule's own
  human-readable text, so the SARIF rule description comes for free.
- `results[].level` is always `"error"` — there's no per-rule severity
  yet, every violation is reported the same way.
- `region` includes `startColumn`/`endLine`/`endColumn` whenever the
  violation carries them (always true for a real run) and omits them
  otherwise, rather than emitting `null` — a region with only
  `startLine` is itself valid SARIF.
- `artifactLocation.uri` always uses `/`, even on a backslash
  filesystem, so the report is portable across CI runners.

## Mermaid

A [Mermaid](https://mermaid.js.org/) `flowchart` at layer granularity,
rendered natively by GitHub and GitLab in Markdown — a PR comment or
README with a ` ```mermaid ` fence needs no separate tool to view it.

**Nodes are layers, not elements.** A group layer renders as a
`subgraph` containing its members' nodes:

```
$ spandrel analyse src --report=mermaid
flowchart LR
    subgraph Core
        Parser
        Graph
    end

    Parser -->|"1"| Graph
```

**Edges are observed dependencies, aggregated per `(fromLayer,
toLayer)` pair** — one edge per pair with at least one dependency
between them, not one per individual `Dependency`. A pair with no
violation among its dependencies is a solid arrow labeled with the
count; a pair with at least one violation is a dotted arrow with both
counts:

```
Domain -->|"12"| Application
Domain -.->|"8 (3 violating)"| Infrastructure
```

Same-layer edges and edges from/to an element with no resolved layer
are omitted — always allowed or never flagged, respectively, so
neither adds anything but clutter. A layer with no edges at all still
renders as a bare node, so it isn't hidden just because nothing
depends on it yet.

**Output is the raw diagram text** — no ` ```mermaid ` fence, no
surrounding Markdown, consistent with JSON/SARIF also emitting bare
content rather than something pre-formatted for one specific consumer.
Wrapping it for a PR comment is the caller's job.

**This is a summary view, not a replacement for exact locations** —
Mermaid's own interactivity isn't reliably supported across renderers,
so there's no way to jump from an edge to a file:line the way SARIF's
locations do. Use Console/JSON/SARIF for that; use Mermaid for "does
the overall shape look right."

Large layer counts get unwieldy fast, so a full, unscoped diagram
refuses to render past **40 layers or 60 edges**, whichever is hit
first:

```
[ERROR] Mermaid diagram would have 41 layers and 0 edges (limit 40 layers, 60 edges) — too large to stay readable.
        Narrow it with --diagram-scope=violations or --diagram-layer=<Name>, or render it anyway with --diagram-force.
```

Refusing rather than silently truncating matches how this codebase
treats every other structurally-risky situation — a diagram that
quietly dropped nodes or edges would misrepresent itself as complete.
`--diagram-scope=violations` (draw only pairs with a violation) and
`--diagram-layer=<Name>` (scope to one layer and its immediate
neighbors) both shrink the diagram; `--diagram-force` renders past the
limit anyway. See [docs/cli.md](cli.md#analyse-alias-analyze) for the
full option reference.
