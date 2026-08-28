<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

/**
 * A raw namespace glob used directly as a rule operand — "an anonymous,
 * rule-scoped layer": matched the same way a declared layer is, but
 * never entered into the ruleset's layer registry.
 */
final class InlinePattern
{
    public function __construct(public readonly string $pattern)
    {
    }
}
