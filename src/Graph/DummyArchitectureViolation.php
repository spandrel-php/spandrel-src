<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Graph;

use Spandrel\Spandrel\Config\Config;

// Deliberate architecture violation, added only to verify the SARIF report
// this repo's CI produces gets picked up by GitHub code scanning now that
// the repo is public. `Graph depends on nothing` (docs/architecture.md) —
// this constructor param breaks that. Not meant to be merged.
final class DummyArchitectureViolation
{
    public function __construct(private readonly Config $config)
    {
    }
}
