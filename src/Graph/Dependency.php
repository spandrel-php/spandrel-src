<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Graph;

final class Dependency
{
    /**
     * A call/throw target `DependencyCollector` couldn't statically resolve to a
     * single name: a dynamic expression, or a bare call PHP could resolve to
     * either a namespaced or (falling back at runtime) global function. Curly
     * braces never appear in a real FQCN, so this can never collide with one.
     */
    public const string UNRESOLVABLE = '{unresolvable}';

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly DependencyKind $kind,
        public readonly string $file,
        public readonly int $line,
        public readonly ?int $column = null,
        public readonly ?int $endLine = null,
        public readonly ?int $endColumn = null,
    ) {
    }
}
