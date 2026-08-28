# Contributing

## Code of Conduct

Be respectful and assume good faith. Disagree on technical merits, not
people — critique the code, not the contributor. Harassment, personal
attacks, and discriminatory language or behavior aren't tolerated.

Report a violation to [dbrumann@gmail.com](mailto:dbrumann@gmail.com).
Reports are handled confidentially; a violation can result in a warning
or removal from the project at the maintainer's discretion.

## Security

Don't open a public issue for a vulnerability — see
[SECURITY.md](SECURITY.md).

## Reporting bugs / requesting features

Open an issue using the provided template. Include the Spandrel
version/commit and PHP version from your environment.

## Setup

No local PHP installation needed — everything runs through Docker:

```sh
docker compose run --rm php composer install
```

## Before opening a PR

```sh
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse
docker compose run --rm php php bin/spandrel.php analyse
```

All three run in CI and must pass. The last one is Spandrel dogfooding
itself against `docs/architecture.md` — a PR that introduces a new
dependency across the declared layers will fail it, same as it would
for any other project using Spandrel.

PHPStan runs at level 9 (max strictness). There's no code style tool
configured yet; match the surrounding code.

## Code conventions

- Comments explain a non-obvious *why* (a hidden invariant, a
  regex-ordering gotcha, a workaround for specific behavior) — not
  *what* the code does. If removing a comment wouldn't confuse a
  future reader, it shouldn't be there.
- The ruleset grammar (`## Layers`/`## Rules`/`## Meta`) is
  canonical-only by design: one spelling per verb/preposition, no
  accepted synonyms. See [docs/ruleset.md](docs/ruleset.md) before
  proposing a new grammar construct.
- Keep `docs/` in sync with behavior you change — `docs/cli.md`,
  `docs/ruleset.md`, and `docs/report.md` are meant to be accurate
  references, not aspirational ones.

## Commit messages and PRs

Explain *why*, not just *what* — the diff already shows what changed.
Reference an issue number when one exists (`Closes #N`). Use the PR
template's test plan checklist.

