# Security Policy

## Supported versions

There's no tagged release yet — only `main` is supported. Security
fixes land there.

## Reporting a vulnerability

Please don't open a public issue for a security vulnerability.

Instead, use GitHub's private reporting for this repository (Security
tab → "Report a vulnerability"), or email dbrumann@gmail.com directly
if you'd rather not use GitHub. Include:

- What you found and why it's a vulnerability.
- Steps to reproduce, or a minimal ruleset/codebase that triggers it.
- The Spandrel commit and PHP version you tested against.

You should get an initial response within a few days. Please give us
reasonable time to investigate and release a fix before disclosing
publicly.

## Scope

Spandrel parses PHP source files (via `nikic/php-parser`) and Markdown
ruleset files as part of static analysis. A maliciously crafted input
file causing a crash, excessive resource use, or arbitrary code
execution during parsing is in scope. Spandrel itself doesn't execute
the code it analyses.
