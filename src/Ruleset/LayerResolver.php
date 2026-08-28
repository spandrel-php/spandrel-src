<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

use Spandrel\Spandrel\Graph\Element;

/**
 * Every element is matched against every layer's `Layer::matches()` (both
 * leaf and group), but only **leaf** layers participate in the
 * one-true-layer partition: an element commonly matches both its own leaf
 * layer and every group that leaf is a member of, which is expected, not
 * ambiguous.
 *
 * External layers never enter the per-element matching loop at all, so
 * they can never claim a parsed element or trigger
 * `AmbiguousLayerMatchException`. They exist to name a vendor pattern for
 * use in rules/`groups`/`except` without requiring that vendor code to be
 * parsed — see `RuleEngine::matchesOperandIgnoringKind()`, which matches
 * them directly against an edge's FQCN instead of via `LayerResolution::layerOf()`.
 */
final class LayerResolver
{
    /**
     * @param Layer[] $layers
     */
    public function __construct(private readonly array $layers)
    {
    }

    /**
     * @param Element[] $elements
     */
    public function resolve(array $elements): LayerResolution
    {
        $matches = [];

        foreach ($this->layers as $layer) {
            $matches[$layer->name] = [];
        }

        $unmatched = [];

        foreach ($elements as $element) {
            $matchedLeafNames = [];

            foreach ($this->layers as $layer) {
                if ($layer->isExternal || !$layer->matches($element->fqcn)) {
                    continue;
                }

                $matches[$layer->name][] = $element;

                if (!$layer->isGroup) {
                    $matchedLeafNames[] = $layer->name;
                }
            }

            if ($matchedLeafNames === []) {
                $unmatched[] = $element;
            } elseif (count($matchedLeafNames) > 1) {
                throw AmbiguousLayerMatchException::forElement($element->fqcn, $matchedLeafNames);
            }
        }

        $leafLayerNames = array_values(array_map(
            static fn (Layer $layer): string => $layer->name,
            array_filter($this->layers, static fn (Layer $layer): bool => !$layer->isGroup),
        ));

        return new LayerResolution($matches, $unmatched, $leafLayerNames);
    }
}
