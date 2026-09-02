<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Reporting;

use Spandrel\Spandrel\RuleEngine\Violation;

/**
 * Console/JSON/SARIF/GitHub report on `$violations` alone — neither the
 * Code Graph nor the Ruleset is ever read. `GraphReporter` is the sibling
 * interface for the one implementation (Mermaid) that needs the full
 * picture to draw a diagram. (GitHub still takes the analysed source
 * paths as constructor state, to rebuild repo-root-relative paths for its
 * annotations, but nothing beyond `$violations` reaches `format()`.)
 *
 * Deliberately not a supertype/subtype relationship, since PHP requires
 * an overriding method's added parameters to be optional and there's no
 * sane default for `Ruleset` here — `AnalyseCommand` dispatches to
 * whichever interface a reporter actually implements.
 */
interface Reporter
{
    /**
     * @param Violation[] $violations
     */
    public function format(array $violations): string;
}
