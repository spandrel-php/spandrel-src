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
}
