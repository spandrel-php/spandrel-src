<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Reporting;

final class MermaidDiagramTooLargeException extends \RuntimeException
{
    public static function forDiagram(int $nodes, int $edges, int $maxNodes, int $maxEdges): self
    {
        return new self(sprintf(
            "Mermaid diagram would have %d layers and %d edges (limit %d layers, %d edges) — too large to stay readable.\n"
            .'Narrow it with --diagram-scope=violations or --diagram-layer=<Name>, or render it anyway with --diagram-force.',
            $nodes,
            $edges,
            $maxNodes,
            $maxEdges,
        ));
    }
}
