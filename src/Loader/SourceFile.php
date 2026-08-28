<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Loader;

final class SourceFile
{
    public function __construct(
        public readonly string $absolutePath,
        public readonly string $relativePath,
    ) {
    }
}
