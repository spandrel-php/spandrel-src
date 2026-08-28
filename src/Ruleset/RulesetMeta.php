<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

final class RulesetMeta
{
    public function __construct(
        public readonly bool $strictElements = false,
        public readonly bool $strictParsing = false,
        public readonly bool $strictLayers = false,
    ) {
    }
}
