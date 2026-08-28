<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

final class TypeHierarchyTarget
{
    public function __construct(public readonly string $fqcn)
    {
    }
}
