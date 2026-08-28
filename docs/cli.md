# CLI Reference

Every command is registered on the `Symfony\Component\Console\Application`
bootstrapped in `bin/spandrel.php`. Bare-verb commands (`analyse`,
`init`, `lint`) are the primary, everyday actions; colon-namespaced
commands (`debug:layers`, `debug:ruleset`, `cache:clear`) are auxiliary —
inspecting or resetting something, not the main thing you run.

```sh
php bin/spandrel.php <command> [arguments] [options]
```

Run any command with `--help` for Symfony's own generated option list;
this page explains what each option actually does and why it exists.
For the ruleset grammar (`## Layers` / `## Rules` / `## Meta`) and
`spandrel.yaml` keys, see [docs/README.md](README.md).

`init`'s scaffolding and `spandrel.yaml`/`architecture.md`
auto-discovery are both relative to the **current working directory**,
not any path passed on the command line.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Success — no violations (`analyse`), or the ruleset/cache operation completed. |
| `1` | Failure — violations found (`analyse`), or an operation couldn't complete (a missing ruleset/config file, a missing cache directory, `init` refusing to overwrite). |
| `2` | Invalid invocation or ruleset — bad option combination, unsupported format, malformed ruleset/config, ambiguous layer match. |

There's no single rule that maps every command to `1` vs `2` for a
load error — see each command's own table below for exactly which
failures return which code.

## `analyse` (alias `analyze`)

Runs the full pipeline — ruleset parse, source parse, layer
resolution, Rule Engine — and reports violations.

```sh
php bin/spandrel.php analyse [paths] [options]
```

| Argument | Meaning |
|---|---|
| `paths` | Source directory to analyse. Defaults to `source.paths` in `spandrel.yaml`. Exit `2` if neither is given. |

| Option | Meaning |
|---|---|
| `--config=<path>` | Path to the tool config file, instead of auto-discovered `spandrel.yaml`. |
| `--ruleset=<path>` | Path to a ruleset file. Repeatable to merge several; defaults to `ruleset` in `spandrel.yaml`, then `architecture.md`. |
| `--report=FORMAT[:OUTPUT]` | `FORMAT` is `console` (default), `json`, `sarif`, or `mermaid`; `OUTPUT` is a file path, or `-` (default) for stdout. Repeatable to emit more than one report from a single run, e.g. `--report=console --report=sarif:report.sarif`. Omit entirely for `console` to stdout. |
| `--diagram-scope=full\|violations` | Mermaid only. `full` (default) draws every observed layer pair; `violations` draws only pairs containing a violation. |
| `--diagram-layer=<Name>` | Mermaid only. Scopes the diagram to one layer (leaf or group) and its immediate neighbors, instead of the whole graph. Exit `2` if the name isn't a declared layer. |
| `--diagram-force` | Mermaid only. Renders past the 40-node/60-edge readability limit instead of refusing with `MermaidDiagramTooLargeException`. |
| `--cache-dir=<path>` | Cache directory to read/write, instead of `cache.directory` in `spandrel.yaml`. |
| `--no-cache` | Disable caching for this run even if a cache directory is configured. |
| `--baseline=<path>` | Baseline file to suppress already-known violations, instead of `baseline` in `spandrel.yaml`. |
| `--generate-baseline` | Write current violations to the baseline file and exit `0`, instead of evaluating pass/fail. Exit `2` if no baseline path is available. |
| `--no-baseline` | Disable baseline suppression for this run even if one is configured. |
| `--strict` | Fail on a PHP parse error, or on an element not covered by any layer, instead of skipping silently. Also turned on by the ruleset's own `## Meta` sentences — see below. |
| `--no-strict` | Force strict mode off for this run, even if the ruleset declares it in `## Meta`. |
| `-v` / `--verbose` | Standard Symfony verbosity flag — under the `console` format, also prints the matched rule's text under each violation line. |

`--strict` bundles two independent checks, and they don't fail the
same way:

- an **element with no layer** is reported per-element and the run
  exits `1` — the same code as an ordinary violation, since "code that
  should have been classified but wasn't" is treated like a rule
  failure, not a config problem;
- a **PHP parse error** is reported per-file and the run exits `2` —
  treated as an invalid run rather than a violation, since no
  Dependency edges could be extracted from that file at all.

Either check can also be turned on independently by the ruleset itself
(`## Meta`'s `Any class not in a layer violates rules.` /
`A file that fails to parse violates rules.`), so a project's
strictness posture doesn't depend on every invocation remembering the
flag. `--no-strict` forces both off regardless of what the ruleset
declares.

Config/ruleset load failures, a malformed or unsupported `--report`
value, an unrecognised `--diagram-scope`, an unknown `--diagram-layer`,
a missing baseline path for `--generate-baseline`, an
`AmbiguousLayerMatchException`, and an oversized Mermaid diagram
(without `--diagram-force`) all exit `2`. Otherwise: `1` if any
violation remains after baseline suppression, `0` if none do (or after
`--generate-baseline` writes successfully).

## `init`

Scaffolds a starter `architecture.md` (four example layers) and
`spandrel.yaml` in the current working directory.

```sh
php bin/spandrel.php init [--force]
```

| Option | Meaning |
|---|---|
| `--force` | Overwrite `architecture.md`/`spandrel.yaml` if either already exists. |

Without `--force`, exits `1` and writes nothing if either file already
exists. Never touches `--config`/`--ruleset` — it always writes to
those two fixed filenames.

## `lint`

Validates a ruleset — grammar, layer-name uniqueness, rule-mode
conflicts, and (given source) layer-resolution sanity — without
evaluating any code for violations.

```sh
php bin/spandrel.php lint [paths] [options]
```

| Argument | Meaning |
|---|---|
| `paths` | Source to resolve layers against. Optional — see below. |

| Option | Meaning |
|---|---|
| `--config=<path>` | Same as `analyse`. |
| `--ruleset=<path>` | Same as `analyse`. |
| `--cache-dir=<path>` | Same as `analyse`. |
| `--no-cache` | Same as `analyse`. |
| `--strict-layers` | Fail if any declared layer is neither a rule subject nor explicitly declared `may depend on anything`. Also turned on by `## Meta`'s `Every layer must be used in a rule.`. |
| `--no-strict-layers` | Force strict-layers mode off for this run, even if the ruleset declares it in `## Meta`. |

Always checked, with no source needed: the ruleset parses, layer names
are unique, no layer carries conflicting rule modes, and (under
`--strict-layers`) every declared layer is used. Checked only when
`paths` is given, since these need a real Code Graph: no element
matches more than one leaf layer (failure), and no declared,
non-external layer matches zero elements (a warning only — printed,
but doesn't change the exit code).

Exit `2` on a config/ruleset load error, a stale layer under
`--strict-layers`, or an `AmbiguousLayerMatchException`. Exit `0`
otherwise — `lint` never exits `1`, since it never evaluates
violations.

## `debug:layers`

Shows every declared layer, its pattern(s), and how many elements
matched — the first stop when a rule "isn't catching anything."

```sh
php bin/spandrel.php debug:layers [path] [options]
```

| Argument | Meaning |
|---|---|
| `path` | Source directory to analyse. Defaults to `source.paths` in `spandrel.yaml`. Exit `1` if neither is given. |

| Option | Meaning |
|---|---|
| `--config=<path>` | Same as `analyse`. |
| `--ruleset=<path>` | Same as `analyse`. |
| `--show-elements` | List every matched (and unmatched) FQCN individually, not just the per-layer count. |
| `--cache-dir=<path>` | Same as `analyse`. |
| `--no-cache` | Same as `analyse`. |

Prints a table of every layer (external layers marked `(external)`),
its patterns, and its match count, followed by an `Unmatched: N` line
for elements no layer claimed. An element landing in `Unmatched` is
silently excluded from analysis by default — not flagged — so a
project can adopt Spandrel one layer at a time; `analyse --strict`
(the element-coverage half) is what opts into failing on it.

Exit `1` on a missing source path or a config/ruleset load error;
`0` otherwise. Note this command takes `path` (singular), unlike every
other command's `paths` argument.

## `debug:ruleset`

Prints the ruleset back out with every piece of sugar resolved: list
bullets expanded to their individual pairwise rules, `depends on
nothing` expanded to its full target list, `may depend on anything`
shown explicitly, and `{Name}` placeholder layers expanded to the
concrete layers they derived.

```sh
php bin/spandrel.php debug:ruleset [paths] [options]
```

| Argument | Meaning |
|---|---|
| `paths` | Source to resolve `{Name}` placeholder layers against. Optional — see below. |

| Option | Meaning |
|---|---|
| `--config=<path>` | Same as `analyse`. |
| `--ruleset=<path>` | Same as `analyse`. |
| `--cache-dir=<path>` | Same as `analyse`. |
| `--no-cache` | Same as `analyse`. |

`## Meta` is printed first, only when at least one policy sentence is
actually true. Without `paths`, a placeholder layer has no Code Graph
to derive against, so its raw `{Name}` template line is printed
instead of silently vanishing.

Exit `1` on a config/ruleset load error; `0` otherwise.

## `cache:clear`

Deletes the cache directory (and its containing directory, if that
directory is then empty).

```sh
php bin/spandrel.php cache:clear [options]
```

| Option | Meaning |
|---|---|
| `--config=<path>` | Same as `analyse`. |
| `--cache-dir=<path>` | Directory to clear, instead of `cache.directory` in `spandrel.yaml`. |

Exit `1` if no cache directory is given (neither `--cache-dir` nor
`cache.directory` configured) or on a config load error; `0` on
success — including when the directory didn't exist to begin with.
