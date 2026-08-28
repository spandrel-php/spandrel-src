<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Graph;

final class Element
{
    public function __construct(
        public readonly string $fqcn,
        public readonly ElementKind $kind,
        public readonly string $file,
        public readonly int $line,
        public readonly ?int $column = null,
    ) {
    }
}
