<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

final class Layer
{
    /**
     * @param string[] $patterns for a leaf layer, its own glob patterns; for a group, the
     *                           flattened union of its members' patterns — informational
     *                           (e.g. `debug:layers`) only, `matches()` uses `$members`
     *                           instead so each member's own `except` is still respected
     * @param string[] $except
     * @param Layer[] $members only set when `$isGroup` is true
     * @param bool $isExternal a named pattern with no `Element` behind it — mutually
     *                         exclusive with `$isGroup`; see `LayerResolver` and
     *                         `RuleEngine::matchesOperandIgnoringKind()` for what this
     *                         exempts it from
     */
    public function __construct(
        public readonly string $name,
        public readonly array $patterns,
        public readonly array $except = [],
        public readonly bool $isGroup = false,
        public readonly array $members = [],
        public readonly bool $isExternal = false,
    ) {
    }

    /**
     * Whether `$leafName` is this layer itself (leaf) or one of its members,
     * recursively (group) — a name-based check, not an FQCN one; see
     * `RuleEngine::matchesOperand()` for why the two are kept separate.
     */
    public function containsLeaf(string $leafName): bool
    {
        if (!$this->isGroup) {
            return $this->name === $leafName;
        }

        foreach ($this->members as $member) {
            if ($member->containsLeaf($leafName)) {
                return true;
            }
        }

        return false;
    }

    public function matches(string $fqcn): bool
    {
        foreach ($this->except as $pattern) {
            if (PatternMatcher::matches($pattern, $fqcn)) {
                return false;
            }
        }

        if ($this->isGroup) {
            foreach ($this->members as $member) {
                if ($member->matches($fqcn)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($this->patterns as $pattern) {
            if (PatternMatcher::matches($pattern, $fqcn)) {
                return true;
            }
        }

        return false;
    }
}
