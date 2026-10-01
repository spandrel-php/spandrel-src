<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

final class Ruleset
{
    /**
     * @param Layer[] $layers
     * @param Rule[] $rules
     * @param string[] $unconstrainedLayers layers explicitly declared `may depend on anything`
     * @param string[] $placeholderTemplates raw `{Name}` template patterns, in file order —
     *                                        already reflected in $layers whenever any Elements
     *                                        were available to derive against; kept here mainly
     *                                        so a source-less caller (`debug:ruleset` without
     *                                        `paths`) can still show what the ruleset *would*
     *                                        derive, rather than silently showing nothing
     * @param RulesetMeta $meta ruleset-declared policy defaults (`## Meta`), independent of
     *                          any CLI flag
     */
    public function __construct(
        public readonly array $layers,
        public readonly array $rules = [],
        public readonly array $unconstrainedLayers = [],
        public readonly array $placeholderTemplates = [],
        public readonly RulesetMeta $meta = new RulesetMeta(),
    ) {
    }

    /**
     * Layers no rule constrains: neither named in a rule nor declared `may depend
     * on anything`, directly or through any group containing them.
     *
     * @return Layer[]
     */
    public function unusedLayers(): array
    {
        $used = [];

        foreach ($this->rules as $rule) {
            if (is_string($rule->subject)) {
                $used[$rule->subject] = true;
            }

            if (is_string($rule->object)) {
                $used[$rule->object] = true;
            }
        }

        foreach ($this->unconstrainedLayers as $name) {
            $used[$name] = true;
        }

        $pending = array_filter($this->layers, static fn (Layer $layer): bool => isset($used[$layer->name]));

        while ($pending !== []) {
            foreach (array_pop($pending)->members as $member) {
                if (!isset($used[$member->name])) {
                    $used[$member->name] = true;
                    $pending[] = $member;
                }
            }
        }

        return array_values(array_filter($this->layers, static fn (Layer $layer): bool => !isset($used[$layer->name])));
    }
}
