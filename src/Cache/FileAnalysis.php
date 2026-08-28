<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Cache;

use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\Element;

final class FileAnalysis
{
    /**
     * @param Element[] $elements
     * @param Dependency[] $dependencies
     */
    public function __construct(
        public readonly string $relativePath,
        public readonly string $contentHash,
        public readonly int $schemaVersion,
        public readonly array $elements,
        public readonly array $dependencies,
    ) {
    }
}
