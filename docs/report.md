# Report Formats

`analyse` reports violations in one of five formats — `console`
(default), `json`, `sarif`, `github`, or `mermaid` — selected with
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

Console, JSON, SARIF, and GitHub report on individual violations alone
— they never read the Code Graph or the Ruleset, only strings already
baked into each `Violation` — so they implement the narrower
`Reporter`. (GitHub additionally takes the analysed source paths as
constructor state, to rebuild repo-root-relative paths for its
annotations; nothing beyond `$violations` reaches `format()`.)
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

## GitHub

[Workflow commands](https://docs.github.com/en/actions/reference/workflow-commands-for-github-actions#setting-an-error-message),
one `::error` per violation, so a run inside GitHub Actions annotates
the offending lines in the pull request's diff — no SARIF upload, no
GitHub Advanced Security, no `security-events: write` involved:

```
$ spandrel analyse src --report=github
::error file=src/Domain/Foo.php,line=4,col=31,endLine=4,endColumn=35,title=Spandrel%3A `Domain` must not depend on `Infrastructure`::App\Domain\Foo (Domain) must not depend on App\Infrastructure\Db (Infrastructure) via param-type
```

- **`file=` is repo-root-relative, not source-relative.** Every other
  format reports `Violation::$file` as it stands — relative to the
  source path it was found under, so `Domain/Foo.php` for `analyse
  src`. GitHub resolves an annotation's path against the repository
  root and silently drops any annotation whose path doesn't exist
  there, so this format puts the source path back in front (the first
  one that actually contains the file, when several were analysed).
  The corollary: **run `analyse` from the repository root**, the
  normal shape of a workflow step. A path that can't be resolved is
  emitted unchanged rather than guessed at.
- `col`/`endLine`/`endColumn` are included whenever the violation
  carries them (always true for a real run) and omitted otherwise.
- `title` is the matched rule's own text, prefixed with `Spandrel:` —
  the annotation heading is the one place a check run mixing several
  tools' annotations can say which tool spoke, and it makes the diff
  annotation read like `console -v` output.
- `%`, CR and LF in the message, plus `,` and `:` in property values,
  are percent-escaped as the workflow-command syntax requires.
- **No violations produces no output at all** — the command *is* the
  message here, so there's nothing to say when there's nothing to
  annotate. Pair it with `console` (`--report=console
  --report=github`) to keep a human-readable summary in the job log.

GitHub caps annotations at 10 errors per step and 50 annotations per
job across all steps ([Actions
limits](https://docs.github.com/en/actions/reference/limits)); past
that the step still fails on the full violation count, but only the
first annotations are drawn on the diff.

## Mermaid

A [Mermaid](https://mermaid.js.org/) `flowchart` at layer granularity,
rendered natively by GitHub and GitLab in Markdown — a PR comment or
README with a ` ```mermaid ` fence needs no separate tool to view it.

**Nodes are layers, not elements**, and every element and connection
has one meaning:

| Diagram | Meaning |
|---|---|
| `Name` (box) | a layer matching code |
| `Name:::empty` (dashed border) | a layer matching no code yet, e.g. a [listed placeholder value](ruleset.md#listing-values-before-the-code-exists) |
| `Name[[Name]]` (double-sided box) | an [external layer](ruleset.md#external-layers): a vendor namespace Spandrel doesn't parse, so arrows only point into it |
| `subgraph` | a group layer |
| `A -->` (thin arrow) | dependencies from `A` to `B`, none violating |
| `A ==>` (thick red arrow) | dependencies from `A` to `B`, at least one violating |

```
$ spandrel analyse src --report=mermaid
flowchart LR
    classDef empty stroke-dasharray: 5 5
    subgraph Runs
        Runs_Domain[Domain]
        Runs_Infrastructure[Infrastructure]
    end
    subgraph Reporting
        Reporting_Domain[Domain]:::empty
    end
    Psr[[Psr]]

    Runs_Domain -->|"3"| Psr
    Runs_Infrastructure ==>|"8 (3 violating)"| Runs_Domain
    linkStyle 1 stroke:#d73a49
```

A layer can only sit in one subgraph, so when groups overlap (every
leaf of a two-capture placeholder is in a module and a layer group),
each leaf goes into the **first declared** group containing it, and a
group with nothing left isn't drawn. For `App\{Module}\{Layer}\**`
that's one subgraph per module. Inside its subgraph, a derived leaf's
label drops the group prefix (`Runs_Domain` shows as `Domain`).

**Edges are observed dependencies, aggregated per `(fromLayer,
toLayer)` pair** — one edge per pair with at least one dependency
between them, not one per individual `Dependency`, labelled with the
count, plus the violating count if any. The rules themselves aren't
drawn: they show up as violating edges, and `debug:ruleset` lists them.

A dependency on a class outside the parsed source counts towards every
external layer whose pattern matches it, the same way rules evaluate
external layers; a class in the parsed source always belongs to its
own layer instead. Same-layer edges and edges from/to anything else
without a layer are omitted — always allowed or never flagged,
respectively, so neither adds anything but clutter. A layer with no
edges at all still renders, so it isn't hidden just because nothing
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
