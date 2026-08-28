<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Reporting;

/**
 * The layer-level model `MermaidReporter::buildDiagram()` aggregates
 * before `render()` serializes it to Mermaid text — split out so
 * `format()` can check `nodeCount()`/`edgeCount()` before committing to
 * render.
 */
final class MermaidDiagram
{
    /**
     * @param array<string, string[]> $subgraphs group layer name => member leaf names
     * @param string[] $bareLeaves leaf layers not claimed by any rendered subgraph
     * @param array<string, array{from: string, to: string, total: int, violating: int}> $pairs keyed by "fromLayer|toLayer"
     */
    public function __construct(
        public readonly array $subgraphs,
        public readonly array $bareLeaves,
        public readonly array $pairs,
    ) {
    }

    public function nodeCount(): int
    {
        $count = count($this->bareLeaves);

        foreach ($this->subgraphs as $members) {
            $count += count($members);
        }

        return $count;
    }

    public function edgeCount(): int
    {
        return count($this->pairs);
    }
}
