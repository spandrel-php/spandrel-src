<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Graph;

/**
 * Classifies an FQCN as one of PHP's own built-ins — for `core
 * functions`/`core classes` reserved targets (`RuleEngine`), and for
 * recognizing a bare, ambiguous function call as a real core function
 * rather than leaving it unresolvable (`Parser\DependencyCollector`).
 * Lives in `Graph` rather than `RuleEngine` since both callers need it
 * and `Parser may only depend on Graph`.
 *
 * `isInternal()` distinguishes "defined by a compiled PHP extension" from
 * "defined in userland PHP source," so Spandrel's own Composer
 * dependencies (real PHP source, loaded in the same process) report
 * `isInternal() === false` rather than being mistaken for built-ins.
 */
final class CoreSymbols
{
    public static function isCoreFunction(string $name): bool
    {
        if (!function_exists($name)) {
            return false;
        }

        return (new \ReflectionFunction($name))->isInternal();
    }

    public static function isCoreClass(string $name): bool
    {
        // Only `class_exists` (not interface_exists/enum_exists): our Instantiate/Throw
        // edges only ever come from `new X()`, and only a concrete class is instantiable.
        if (!class_exists($name, false)) {
            return false;
        }

        return (new \ReflectionClass($name))->isInternal();
    }
}
