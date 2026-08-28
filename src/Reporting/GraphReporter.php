<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Reporting;

use Spandrel\Spandrel\Graph\CodeGraph;
use Spandrel\Spandrel\RuleEngine\Violation;
use Spandrel\Spandrel\Ruleset\Ruleset;

/**
 * Sibling to `Reporter`, not a supertype/subtype of it — see that
 * interface's docblock for why. For a reporter that needs the full
 * Code Graph and Ruleset, not just the violation list (currently only
 * Mermaid, which needs `Layers`/`Rules` to know what the graph's nodes
 * and groupings are, and every observed dependency, not only the ones
 * that violated something).
 */
interface GraphReporter
{
    /**
     * @param Violation[] $violations
     */
    public function format(array $violations, CodeGraph $graph, Ruleset $ruleset): string;
}
