<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\RuleEngine;

use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\DependencyKind;

/**
 * The transitive `extends`/`implements` ancestor closure over a set of
 * `Dependency` edges, for the `subtypes of` operand. `UseTrait` is
 * excluded — using a trait isn't an is-a relationship. Reflexive: a type
 * counts as its own subtype.
 *
 * The walk stops wherever the collected edges stop (e.g. at a vendor base
 * class with no further `Element`); seeing further up a vendor hierarchy
 * requires that path to be parsed too.
 */
final class TypeHierarchy
{
    /** @var array<string, string[]> */
    private readonly array $superTypesByFqcn;

    /** @var array<string, array<string, true>> */
    private array $ancestorCache = [];

    /**
     * @param Dependency[] $dependencies
     */
    public function __construct(array $dependencies)
    {
        $superTypes = [];

        foreach ($dependencies as $dependency) {
            if ($dependency->kind === DependencyKind::Extends || $dependency->kind === DependencyKind::Implements) {
                $superTypes[$dependency->from][] = $dependency->to;
            }
        }

        $this->superTypesByFqcn = $superTypes;
    }

    public function isSubtypeOf(string $fqcn, string $ancestorFqcn): bool
    {
        return isset($this->ancestors($fqcn, [])[$ancestorFqcn]);
    }

    /**
     * Memoized per FQCN, so a shared ancestor in a diamond-shaped hierarchy is only
     * walked once. `$visiting` guards against a cycle even though real PHP
     * `extends`/`implements` semantics can't produce one.
     *
     * @param array<string, true> $visiting FQCNs currently being walked, this call stack only
     * @return array<string, true>
     */
    private function ancestors(string $fqcn, array $visiting): array
    {
        if (isset($this->ancestorCache[$fqcn])) {
            return $this->ancestorCache[$fqcn];
        }

        $result = [$fqcn => true];

        if (isset($visiting[$fqcn])) {
            return $result;
        }

        $visiting[$fqcn] = true;

        foreach ($this->superTypesByFqcn[$fqcn] ?? [] as $superType) {
            foreach ($this->ancestors($superType, $visiting) as $ancestor => $_) {
                $result[$ancestor] = true;
            }
        }

        $this->ancestorCache[$fqcn] = $result;

        return $result;
    }
}
