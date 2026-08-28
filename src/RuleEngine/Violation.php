<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\RuleEngine;

use Spandrel\Spandrel\Graph\DependencyKind;

final class Violation
{
    public function __construct(
        public readonly string $rule,
        public readonly string $fromElement,
        public readonly string $toElement,
        public readonly DependencyKind $dependencyKind,
        public readonly string $file,
        public readonly int $line,
        public readonly string $message,
        public readonly ?int $column = null,
        public readonly ?int $endLine = null,
        public readonly ?int $endColumn = null,
    ) {
    }
}
