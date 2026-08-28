<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

enum RuleVerb
{
    case MustNotDependOn;
    case MayOnlyDependOn;

    public function asPhrase(): string
    {
        return match ($this) {
            self::MustNotDependOn => 'must not depend on',
            self::MayOnlyDependOn => 'may only depend on',
        };
    }
}
