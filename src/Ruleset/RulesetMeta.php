<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

final class RulesetMeta
{
    public function __construct(
        public readonly bool $failOnUnmatchedElements = false,
        public readonly bool $failOnParseErrors = false,
        public readonly bool $failOnUnusedLayers = false,
    ) {
    }
}
