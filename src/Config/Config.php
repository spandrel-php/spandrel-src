<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Config;

final class Config
{
    /**
     * @param string[] $sourcePaths
     * @param string[] $excludePaths
     */
    public function __construct(
        public readonly array $sourcePaths,
        public readonly array $excludePaths = [],
        public readonly ?string $ruleset = null,
        public readonly ?string $cacheDirectory = null,
        public readonly ?string $baselinePath = null,
    ) {
    }
}
