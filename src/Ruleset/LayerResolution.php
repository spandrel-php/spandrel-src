<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

use Spandrel\Spandrel\Graph\Element;

final class LayerResolution
{
    /** @var array<string, string> FQCN => layer name, leaf layers only */
    private readonly array $layerByFqcn;

    /**
     * @param array<string, Element[]> $matches Layer name => matched elements (leaf and group layers).
     * @param Element[] $unmatched
     * @param string[]|null $leafLayerNames names eligible for layerOf()'s one-true-layer index;
     *                                      null (the default) means every key in $matches — safe for
     *                                      any caller that doesn't have group layers in play at all.
     */
    public function __construct(
        public readonly array $matches,
        public readonly array $unmatched,
        ?array $leafLayerNames = null,
    ) {
        $eligible = array_flip($leafLayerNames ?? array_keys($this->matches));
        $layerByFqcn = [];

        foreach ($this->matches as $layerName => $elements) {
            if (!isset($eligible[$layerName])) {
                continue;
            }

            foreach ($elements as $element) {
                $layerByFqcn[$element->fqcn] = $layerName;
            }
        }

        $this->layerByFqcn = $layerByFqcn;
    }

    public function layerOf(string $fqcn): ?string
    {
        return $this->layerByFqcn[$fqcn] ?? null;
    }
}
