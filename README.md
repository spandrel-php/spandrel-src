# Spandrel

Spandrel is a static analysis tool for PHP applications. It parses a codebase into a graph of architectural elements (classes, interfaces, traits, enums) and their dependencies, evaluates that graph against a human- and agent-readable set of architecture rules, and reports any violations in a format suited to the consumer — a terminal, a CI pipeline, or an agent.

## Installation

This repository holds Spandrel's source. Released builds — a signed
`spandrel.phar` and the `spandrel/spandrel` Composer package — are
published to [spandrel-php/spandrel](https://github.com/spandrel-php/spandrel):

```sh
composer require --dev spandrel/spandrel
vendor/bin/spandrel analyse
```

The rest of this README covers working on Spandrel's own source — building
and testing this repo, not using Spandrel against your project. For that,
see the [User Guide](docs/README.md).

## How Spandrel compares

### vs. Deptrac

Deptrac established this space for PHP, and Spandrel deliberately keeps
the same core mental model — layers, rules, dependency violations.
Where it differs:

- **Ruleset format** — Markdown prose instead of YAML. The same
document a human reads to learn a codebase's boundaries is the one an
LLM agent parses and edits, with no separate schema to hold in mind.
- **Rule vocabulary** — verb-based sentences (`must not depend on`,
`may only depend on`) that can be scoped to a specific dependency kind
(`must not extend`, `must not call`) or to a type hierarchy (`subtypes
of X must not depend on Y`), rather than a single per-layer allow-list.
- **Output** — SARIF (renders inline in GitHub's code-scanning UI) and
Mermaid (renders directly in GitHub-flavored Markdown) as first-class
formatters alongside console and JSON.
- Targets PHP 8.5 only, with no goal of supporting older versions.

### vs. PHPStan

Different problem entirely: PHPStan checks the shape of an element
(types, nullability, dead code); Spandrel checks the direction of
dependencies between elements (layering). Spandrel deliberately
doesn't reimplement type-shape checking and is meant to run alongside PHPStan in the same pipeline rather
than replace it, the way Spandrel's own CI runs both.

## Requirements

- [Docker](https://www.docker.com/) with the Compose plugin

No local PHP installation is required — all tooling runs inside a container.

## Getting started

Install dependencies:

```sh
docker compose run --rm php composer install
```

Run the CLI:

```sh
docker compose run --rm php php bin/spandrel.php
```

To run Spandrel against your own project and write a ruleset for it, 
see the [User Guide](docs/README.md).

## Development

Run the test suite:

```sh
docker compose run --rm php vendor/bin/phpunit
```

Run static analysis on Spandrel's own codebase:

```sh
docker compose run --rm php vendor/bin/phpstan analyse
```

Generate an HTML code coverage report (written to `coverage/`, gitignored):

```sh
XDEBUG_MODE=coverage docker compose run --rm php vendor/bin/phpunit --coverage-html coverage
```

Enable Xdebug (step debugging) for a single run:

```sh
XDEBUG_MODE=debug,develop docker compose run --rm php php bin/spandrel.php
```

## Project structure

```
bin/spandrel.php   CLI entrypoint (Symfony Console)
src/                Source code (Spandrel\Spandrel\ namespace)
tests/              PHPUnit tests
docker/php          Docker image used to run PHP, Composer and Xdebug
```

