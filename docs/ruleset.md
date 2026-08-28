# Ruleset, Layers, and Meta

A ruleset is a Markdown file — `architecture.md` by default — that
defines the architecture `analyse`, `lint`, `debug:layers`, and
`debug:ruleset` all work against. Markdown so it's readable in a PR
diff, editable by a human or an agent without learning a proprietary
config syntax, and can double as living documentation of the intended
architecture: the same file *is* the rule and the doc.

Three `##` sections are meaningful to the parser: `Meta`, `Layers`, and
`Rules`. Everything else — prose, extra headings, worked examples,
even a trailing `## Diagram` (see `architecture.md` for a real
example) — is ignored, so narrative and rules can live side by side.
This page covers the `Ruleset` object itself, `## Layers`, and
`## Meta`; the `## Rules` verb grammar is its own topic.

Discovery and the `--config`/`--ruleset` options are covered in
[docs/cli.md](cli.md). Several ruleset files can be merged by
repeating `--ruleset` — they're parsed as one logical ruleset: layer
names must be unique and a subject's rule mode must not conflict
across *all* of them, exactly as within a single file, and a
`group`/`except`/rule in one file can reference a layer declared in
another. Each file keeps its own line numbers; an error gets its file
name prefixed only when more than one file is involved.

## Error reporting

A malformed bullet produces an actionable error: the line number, the
offending text, and — where the mistake is recognisable — an
`Expected:` form to compare against:

```
$ php bin/spandrel.php lint --ruleset=bad.md
[ERROR] line 3: could not parse layer bullet
          - Domain lives in App\Domain
        Expected: - **Name**: `pattern`[, `pattern`...]
```

Merging two files that both declare a layer named `A` fails the same
way, with the second file's path prepended:

```
[ERROR] f2.md: line 2: layer "A" is already declared
```

## Layers

A named group of namespace glob patterns, rather than writing rules
directly between raw namespaces — a real ruleset's rules tend to apply
to many namespaces at once, and naming the group once decouples the
ruleset from incidental namespace churn.

```markdown
## Layers

- **Domain**: `App\Domain\**`
- **Application**: `App\Application\**`
- **Infrastructure**: `App\Infrastructure\**`
```

There are four ways to declare one:

| Form | Syntax | Use for |
|---|---|---|
| Explicit | `` - **Name**: `pattern`[, `pattern`...] [except ...] `` | The common case — a stable, named concept. |
| [Placeholder](#auto-derived-layers-placeholders) | `` - `App\{Name}\**` `` | One layer per distinct namespace segment, derived from the code — no per-layer bullet needed. |
| [Group](#group-layers) | `` - **Name** groups `A` and `B` [except ...] `` | A named union of already-declared layers. |
| [External](#external-layers) | `` - **Name** matches `pattern`[, `pattern`...] [except ...] `` | A vendor/third-party namespace — no `Element` behind it, nothing needed in `source.paths`. |

`Name` must match `[A-Za-z][A-Za-z0-9_]*` and be unique across every
merged file, regardless of form. A `pattern` is backtick-quoted and
`\`-separated: `*` matches exactly one namespace segment, `**` matches
zero or more (in any position, not just trailing). A pattern with no
wildcard matches exactly one FQCN. Several patterns can be listed to
union multiple globs under one name; the connecting word between
backtick tokens (comma, `and`, `or`) is cosmetic only — the parser
extracts every backtick-quoted token in order and doesn't validate
what's between them.

### `except`

Any of the four forms can carry a trailing `except`, subtracting one
or more layers/patterns from what would otherwise match:

```markdown
## Layers

- **Y**: `App\Y\**` except `App\Y\Exception\**`
- **Exception**: `App\*\Exception\**`
```

```
$ php bin/spandrel.php debug:layers src
 -----------  --------------------  ---------
  Layer        Patterns              Matched
 -----------  --------------------  ---------
  Y            App\Y\**              1
  Exception    App\*\Exception\**    1
 -----------  --------------------  ---------

Unmatched: 0
```

This is what makes a namespace like exceptions — usually nested
*inside* a business layer's own namespace, but conceptually its own
cross-cutting concern — separable at all: without the `except`,
`App\Y\Exception\**` would match both `Y` and `Exception`, which is an
**ambiguous match** (below), not a silent pick.

Excepting *by layer reference* is resolved against that layer's own
raw patterns, not its final post-`except` membership, so two layers
excepting each other can't create a mechanical infinite loop — but
it's still almost certainly a mistake, so it's a load-time error
(not a soft warning) all the same:

```
[ERROR] line 3: layer "A" is part of an `except` cycle (a layer cannot indirectly except itself)
```

### One layer per element

Every element the Code Graph discovers is matched against every
**leaf** layer's patterns (leaf, not layer in general — group layers
are a deliberate exception, below):

- **No match** → excluded from analysis entirely, silently by
  default — this is intentional, so a project can adopt Spandrel one
  part of the codebase at a time (`debug:layers`' `Unmatched: N`
  count, and `analyse --strict`'s element-coverage half, are the two
  ways to stop ignoring it).
- **Exactly one match** → the element belongs to that layer.
- **More than one match** → a **ruleset load error**, naming every
  conflicting layer:

  ```
  [ERROR] "App\Y\Exception\Bar" matches more than one layer: Y, Exception
  ```

  Reported eagerly rather than resolved by first-match-wins, since
  silent resolution would be unpredictable in a file meant to be
  edited by hand or by an agent.

### Group layers

A layer can be a named union of *other* layers instead of patterns:

```markdown
- **Bar**: `App\Bar\**`
- **Baz**: `App\Baz\**`
- **Foo** groups `Bar` and `Baz`
```

`groups` takes only layer references (leaf or group, nestable), not
raw patterns — if a group needs to also cover a raw pattern, declare a
leaf layer for it and group that in. This is a distinct keyword rather
than reusing `**Name**: pattern`'s union grammar on purpose: a group's
overlap with its members is *expected*, and that's worth being visible
in the bullet, not inferred from whether the list happens to contain
layer names.

An element in `Bar` now matches both `Bar` and `Foo` — exactly what
the one-layer-per-element check above forbids in general. Two things
make that work:

- A group is flattened to its members' patterns once, at load time;
  after that it behaves like any leaf layer everywhere else (rules,
  `except`, element-kind filters).
- The ambiguous-match check **exempts overlap that comes from a
  declared `groups` relationship** specifically. An element matching
  two *unrelated* layers with no group relationship between them is
  still the same hard error as always.

Groups can nest (`Mega groups Foo and Qux`), so a cycle is a load-time
error, the same way an `except` cycle is:

```
[ERROR] line 4: group "Foo" is part of a cycle (a group cannot transitively contain itself)
```

`except` composes normally on a group bullet, subtracting from the
flattened union: `` **Foo** groups `Bar` and `Baz` except `Bar\Legacy` ``.

### External layers

A layer can also be a named pattern with **no `Element` behind it** —
for naming a third-party namespace (Symfony, PSR interfaces, ...)
without adding it to `source.paths`:

```markdown
- **SymfonyConsole** matches `Symfony\Component\Console\**`
```

Identical grammar to an explicit layer, just a different keyword.
Once declared, it's usable anywhere a layer name is valid: as a rule
subject or object, inside `groups`, as the target of another layer's
`except`. It matches an edge straight against its own raw pattern —
the same way a rule's inline pattern does — rather than through the
codebase's parsed elements, so:

- it never claims one of the codebase's own elements, can't
  participate in the one-leaf-layer check, and can never trigger an
  ambiguous-match error;
- `## Meta`'s "Any class not in a layer violates rules." is unaffected
  by it either way — it can't be the thing that turns an
  otherwise-unmatched class into a matched one;
- `lint`'s "declared layer matched zero elements" warning treats an
  external layer's zero count as expected by design, not worth a
  warning.

What it can't do: an **element-kind filter** (`` interfaces in `SymfonyConsole` ``)
still needs a real parsed `Element` to know the matched class's actual
kind — that's exactly the information skipping parsing gives up. A
kind-filtered rule against vendor code needs that vendor path added to
`source.paths` regardless of whether it's named as an external layer.

### Auto-derived layers (placeholders)

Writing one bullet per layer doesn't scale once layers correspond 1:1
with a namespace segment. A single templated bullet derives all of
them at once:

```
- `<pattern with one or two {Name} segments>`
```

`{Name}` occupies exactly one namespace segment (like a single `*`)
but also captures that segment's text as a layer name. For every
distinct value the Code Graph finds at that position, Spandrel creates
a layer named after the captured text. Explicit and templated bullets
can be mixed freely in the same `## Layers` section; a derived name
colliding with another layer's name is the same load error as any
other duplicate.

The segments *before* the last `{Name}` can't be `**` — same ambiguity
a single capture already avoids (which split the capture belongs to).

**A second `{Name}` derives a two-dimensional set** — one leaf layer
per distinct combination on both axes (`_`-joined, in template order),
plus one **group** layer per distinct value on *each* axis, for free:

```markdown
## Layers

- `{Module}\{Layer}\**`
```

Given `Billing\Domain`, `Billing\Infrastructure`, and
`Shipping\Domain` namespaces (plus two rules using each axis — `Domain`
must not depend on `Infrastructure`, `Billing` must not depend on
`Shipping` — and one `may depend on anything` per leaf so `lint
--strict-layers` doesn't flag them as unused), this derives three leaf
layers plus four ordinary group layers — one per module, one per
sub-layer. Leaf and group names come out in Code Graph discovery
order, not alphabetically:

```
$ php bin/spandrel.php debug:ruleset src
## Layers

- **Shipping_Domain**: `Shipping\Domain\**`
- **Billing_Domain**: `Billing\Domain\**`
- **Billing_Infrastructure**: `Billing\Infrastructure\**`
- **Shipping**: `Shipping\Domain\**`
- **Billing**: `Billing\Domain\**`, `Billing\Infrastructure\**`
- **Domain**: `Shipping\Domain\**`, `Billing\Domain\**`
- **Infrastructure**: `Billing\Infrastructure\**`

## Rules

- `Shipping_Domain` may depend on anything
- `Billing_Domain` may depend on anything
- `Billing_Infrastructure` may depend on anything
- `Billing` must not depend on `Shipping`
- `Domain` must not depend on `Infrastructure`
```

The last two rules are what target each axis with zero new grammar —
`Domain` and `Infrastructure` are ordinary auto-derived group layers,
matched exactly like a hand-written `groups` bullet would be.

Two captures is the cap; a third `{Name}` is a parse error, not a
guess at a naming scheme past two axes. Because layers can now appear
automatically as soon as a matching namespace exists, a layer can show
up with zero human decision behind it — which is part of why `may
depend on anything` exists as an explicit rule: it's the only way to
tell "reviewed, deliberately open" apart from "nobody's written a rule
for this yet" once layers stop being hand-declared.

Without any source to derive against (e.g. `debug:ruleset` run with no
`paths`), a placeholder bullet can't derive anything — its raw
template line is shown as-is instead of silently vanishing.

## Meta

Optional, and rare — most rulesets don't need it. Ruleset-wide policy
that's also expressible as a CLI flag, stated here instead so it's
versioned with the rules themselves and doesn't depend on every
invocation remembering the right flag:

```markdown
## Meta

- Any class not in a layer violates rules.
- A file that fails to parse violates rules.
- Every layer must be used in a rule.
```

| Sentence | Equivalent to |
|---|---|
| `Any class not in a layer violates rules.` | `analyse --strict` (element-coverage half) |
| `A file that fails to parse violates rules.` | `analyse --strict` (parse-error half) |
| `Every layer must be used in a rule.` | `lint --strict-layers` |

Each is a fixed, canonical sentence, matched exactly — no synonyms.
The first two are independent halves of `--strict`: they were always
one bundled CLI flag, but nothing requires wanting both at once (a
ruleset mid-migration might want to fail on a stray unmatched class
without also demanding every file parse cleanly yet), so `## Meta` can
turn on either alone. A CLI flag always wins over what's declared
here: `--strict`/`--strict-layers` turn a policy on regardless of
`## Meta`, and `--no-strict`/`--no-strict-layers` force it off
regardless — `## Meta` only supplies the default when neither flag is
given. See [docs/cli.md](cli.md#analyse-alias-analyze) for exactly how
`analyse`/`lint` fail once one of these is on.

A bullet that doesn't match one of the three exactly is a parse error
listing all three canonical forms, not a fuzzy "did you mean" guess:

```
[ERROR] line 3: could not parse meta bullet
          - Every class must be nice.
        Expected one of:
          - Any class not in a layer violates rules.
          - A file that fails to parse violates rules.
          - Every layer must be used in a rule.
```
