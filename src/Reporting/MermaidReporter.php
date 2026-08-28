<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Reporting;

use Spandrel\Spandrel\Graph\CodeGraph;
use Spandrel\Spandrel\RuleEngine\Violation;
use Spandrel\Spandrel\Ruleset\Layer;
use Spandrel\Spandrel\Ruleset\LayerResolver;
use Spandrel\Spandrel\Ruleset\Ruleset;

/**
 * A Mermaid `flowchart` at layer granularity. Nodes are layers (a group
 * renders as a `subgraph` containing its members' leaf names, one level
 * deep); edges are observed dependencies aggregated per `(fromLayer,
 * toLayer)` pair — solid + count when compliant, dotted + `"N (M
 * violating)"` when any dependency in that pair violated a rule.
 * Same-layer and layer-less edges are omitted.
 *
 * `$layerName`, when given, scopes the diagram to one layer's immediate
 * neighborhood: only pairs touching it survive, and only the layers those
 * pairs mention render at all.
 *
 * `format()` splits into `buildDiagram()` (aggregation into a
 * `MermaidDiagram` model) and `render()` (model to Mermaid text) so
 * `MAX_NODES`/`MAX_EDGES` can be checked before rendering; `$force` skips
 * that check. An oversized diagram throws (`MermaidDiagramTooLargeException`)
 * rather than silently truncating.
 */
final class MermaidReporter implements GraphReporter
{
    // Readability heuristic, not a Mermaid/GitHub rendering limit.
    private const MAX_NODES = 40;
    private const MAX_EDGES = 60;

    /**
     * @param string|null $layerName scope to this layer (leaf or group) and its immediate
     *                                neighbors; null renders everything
     */
    public function __construct(
        private readonly bool $violationsOnly = false,
        private readonly ?string $layerName = null,
        private readonly bool $force = false,
    ) {
    }

    /**
     * @param Violation[] $violations
     *
     * @throws MermaidDiagramTooLargeException
     */
    public function format(array $violations, CodeGraph $graph, Ruleset $ruleset): string
    {
        $diagram = $this->buildDiagram($violations, $graph, $ruleset);

        if (!$this->force && ($diagram->nodeCount() > self::MAX_NODES || $diagram->edgeCount() > self::MAX_EDGES)) {
            throw MermaidDiagramTooLargeException::forDiagram(
                $diagram->nodeCount(),
                $diagram->edgeCount(),
                self::MAX_NODES,
                self::MAX_EDGES,
            );
        }

        return $this->render($diagram);
    }

    /**
     * @param Violation[] $violations
     */
    private function buildDiagram(array $violations, CodeGraph $graph, Ruleset $ruleset): MermaidDiagram
    {
        $resolution = (new LayerResolver($ruleset->layers))->resolve($graph->elements);

        $violatingKeys = [];

        foreach ($violations as $violation) {
            $violatingKeys[self::key($violation->file, $violation->line, $violation->fromElement, $violation->toElement)] = true;
        }

        /** @var array<string, array{from: string, to: string, total: int, violating: int}> $pairs */
        $pairs = [];

        foreach ($graph->dependencies as $dependency) {
            $fromLayer = $resolution->layerOf($dependency->from);
            $toLayer = $resolution->layerOf($dependency->to);

            if ($fromLayer === null || $toLayer === null || $fromLayer === $toLayer) {
                continue;
            }

            $pairKey = $fromLayer.'|'.$toLayer;
            $pairs[$pairKey] ??= ['from' => $fromLayer, 'to' => $toLayer, 'total' => 0, 'violating' => 0];
            $pairs[$pairKey]['total']++;

            if (isset($violatingKeys[self::key($dependency->file, $dependency->line, $dependency->from, $dependency->to)])) {
                $pairs[$pairKey]['violating']++;
            }
        }

        if ($this->violationsOnly) {
            $pairs = array_filter($pairs, static fn (array $pair): bool => $pair['violating'] > 0);
        }

        /** @var array<string, true>|null $relevantLeaves null means unscoped — every layer renders */
        $relevantLeaves = null;

        if ($this->layerName !== null) {
            $scopedLayer = self::findLayer($ruleset->layers, $this->layerName);
            $scopeLeaves = $scopedLayer !== null && $scopedLayer->isGroup
                ? self::leafNames($scopedLayer)
                : [$this->layerName];

            $relevantLeaves = array_fill_keys($scopeLeaves, true);

            $pairs = array_filter(
                $pairs,
                static fn (array $pair): bool => isset($relevantLeaves[$pair['from']]) || isset($relevantLeaves[$pair['to']]),
            );

            foreach ($pairs as $pair) {
                $relevantLeaves[$pair['from']] = true;
                $relevantLeaves[$pair['to']] = true;
            }
        }

        /** @var array<string, string[]> $subgraphs */
        $subgraphs = [];
        $claimed = [];

        foreach ($ruleset->layers as $layer) {
            if (!$layer->isGroup) {
                continue;
            }

            $memberNames = $relevantLeaves === null
                ? self::leafNames($layer)
                : array_values(array_filter(self::leafNames($layer), static fn (string $name): bool => isset($relevantLeaves[$name])));

            if ($memberNames === []) {
                continue;
            }

            $subgraphs[$layer->name] = $memberNames;

            foreach ($memberNames as $leafName) {
                $claimed[$leafName] = true;
            }
        }

        $bareLeaves = [];

        foreach ($ruleset->layers as $layer) {
            if ($layer->isGroup || isset($claimed[$layer->name])) {
                continue;
            }

            if ($relevantLeaves !== null && !isset($relevantLeaves[$layer->name])) {
                continue;
            }

            $bareLeaves[] = $layer->name;
        }

        return new MermaidDiagram($subgraphs, $bareLeaves, $pairs);
    }

    private function render(MermaidDiagram $diagram): string
    {
        $lines = ['flowchart LR'];

        foreach ($diagram->subgraphs as $name => $members) {
            $lines[] = sprintf('    subgraph %s', $name);

            foreach ($members as $leafName) {
                $lines[] = sprintf('        %s', $leafName);
            }

            $lines[] = '    end';
        }

        foreach ($diagram->bareLeaves as $leafName) {
            $lines[] = sprintf('    %s', $leafName);
        }

        $pairs = $diagram->pairs;

        if ($pairs !== []) {
            ksort($pairs);

            $lines[] = '';

            foreach ($pairs as $pair) {
                $lines[] = $pair['violating'] > 0
                    ? sprintf('    %s -.->|"%d (%d violating)"| %s', $pair['from'], $pair['total'], $pair['violating'], $pair['to'])
                    : sprintf('    %s -->|"%d"| %s', $pair['from'], $pair['total'], $pair['to']);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function key(string $file, int $line, string $from, string $to): string
    {
        return $file.':'.$line.':'.$from.':'.$to;
    }

    /**
     * @param Layer[] $layers
     */
    private static function findLayer(array $layers, string $name): ?Layer
    {
        foreach ($layers as $layer) {
            if ($layer->name === $name) {
                return $layer;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private static function leafNames(Layer $layer): array
    {
        $names = [];

        foreach ($layer->members as $member) {
            if ($member->isGroup) {
                array_push($names, ...self::leafNames($member));
            } else {
                $names[] = $member->name;
            }
        }

        return $names;
    }
}
