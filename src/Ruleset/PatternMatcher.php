<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

/**
 * Matches a `\`-separated namespace glob against a fully-qualified name.
 *
 * `*` matches exactly one segment; `**` matches zero or more segments,
 * in any position (not just trailing).
 */
final class PatternMatcher
{
    public static function matches(string $pattern, string $fqcn): bool
    {
        $patternSegments = explode('\\', ltrim($pattern, '\\'));
        $fqcnSegments = explode('\\', ltrim($fqcn, '\\'));

        return self::matchSegments($patternSegments, $fqcnSegments);
    }

    /**
     * @param string[] $pattern
     * @param string[] $fqcn
     */
    private static function matchSegments(array $pattern, array $fqcn): bool
    {
        if ($pattern === []) {
            return $fqcn === [];
        }

        $head = array_shift($pattern);

        if ($head === '**') {
            if ($pattern === []) {
                return true;
            }

            for ($i = 0, $count = count($fqcn); $i <= $count; $i++) {
                if (self::matchSegments($pattern, array_slice($fqcn, $i))) {
                    return true;
                }
            }

            return false;
        }

        if ($fqcn === []) {
            return false;
        }

        $fqcnHead = array_shift($fqcn);

        if ($head === '*' || $head === $fqcnHead) {
            return self::matchSegments($pattern, $fqcn);
        }

        return false;
    }
}
