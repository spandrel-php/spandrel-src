<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Reporting;

use Spandrel\Spandrel\Graph\CodeGraph;
use Spandrel\Spandrel\RuleEngine\Violation;
use Spandrel\Spandrel\Ruleset\Layer;
use Spandrel\Spandrel\Ruleset\LayerResolver;
use Spandrel\Spandrel\Ruleset\Ruleset;

/**
 * A Mermaid `flowchart` at layer granularity; docs/report.md documents the
 * encodings. Nodes are leaf layers: an external layer as `Name[[Name]]`, a
 * leaf matching no element with a dashed border (`:::empty`). A group renders
 * as a `subgraph` of its leaves, one level deep; since a node can only sit in
 * one subgraph, each leaf goes to the first declared group containing it, and
 * a derived `<Group>_` prefix is dropped from its label there.
 *
 * Edges are observed dependencies aggregated per `(fromLayer, toLayer)` pair:
 * thin + count when compliant, thick red + `"N (M violating)"` when any
 * dependency in the pair violated a rule. A target outside the parsed source
 * counts towards every external layer whose pattern it matches, as the Rule
 * Engine evaluates them. Same-layer and layer-less edges are omitted.
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

    private const VIOLATING_STROKE = '#d73a49';

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

        $externalLayers = array_values(array_filter($ruleset->layers, static fn (Layer $layer): bool => $layer->isExternal));

        /** @var array<string, string[]> $externalTargets FQCN => matching external layer names */
        $externalTargets = [];

        /** @var array<string, array{from: string, to: string, total: int, violating: int}> $pairs */
        $pairs = [];

        foreach ($graph->dependencies as $dependency) {
            $fromLayer = $resolution->layerOf($dependency->from);

            if ($fromLayer === null) {
                continue;
            }

            // An internal element always wins over an external pattern.
            $toLayer = $resolution->layerOf($dependency->to);
            $toLayers = $toLayer !== null
                ? [$toLayer]
                : $externalTargets[$dependency->to] ??= array_values(array_map(
                    static fn (Layer $layer): string => $layer->name,
                    array_filter($externalLayers, static fn (Layer $layer): bool => $layer->matches($dependency->to)),
                ));

            $violating = isset($violatingKeys[self::key($dependency->file, $dependency->line, $dependency->from, $dependency->to)]);

            foreach ($toLayers as $to) {
                if ($to === $fromLayer) {
                    continue;
                }

                $pairKey = $fromLayer.'|'.$to;
                $pairs[$pairKey] ??= ['from' => $fromLayer, 'to' => $to, 'total' => 0, 'violating' => 0];
                $pairs[$pairKey]['total']++;

                if ($violating) {
                    $pairs[$pairKey]['violating']++;
                }
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

            $memberNames = array_values(array_filter(
                array_unique(self::leafNames($layer)),
                static fn (string $name): bool => !isset($claimed[$name]) && ($relevantLeaves === null || isset($relevantLeaves[$name])),
            ));

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

        $emptyLayers = [];

        foreach ($ruleset->layers as $layer) {
            if (!$layer->isGroup && !$layer->isExternal && ($resolution->matches[$layer->name] ?? []) === []) {
                $emptyLayers[$layer->name] = true;
            }
        }

        return new MermaidDiagram(
            $subgraphs,
            $bareLeaves,
            $pairs,
            array_fill_keys(array_map(static fn (Layer $layer): string => $layer->name, $externalLayers), true),
            $emptyLayers,
        );
    }

    private function render(MermaidDiagram $diagram): string
    {
        $lines = ['flowchart LR'];

        $renderedLeaves = array_merge($diagram->bareLeaves, ...array_values($diagram->subgraphs));

        if (array_intersect_key($diagram->emptyLayers, array_flip($renderedLeaves)) !== []) {
            $lines[] = '    classDef empty stroke-dasharray: 5 5';
        }

        foreach ($diagram->subgraphs as $name => $members) {
            $lines[] = sprintf('    subgraph %s', $name);

            foreach ($members as $leafName) {
                $lines[] = '        '.self::node($diagram, $leafName, $name);
            }

            $lines[] = '    end';
        }

        foreach ($diagram->bareLeaves as $leafName) {
            $lines[] = '    '.self::node($diagram, $leafName, null);
        }

        $pairs = $diagram->pairs;

        if ($pairs !== []) {
            ksort($pairs);

            $lines[] = '';

            // linkStyle addresses edges by their position in the output.
            $violatingIndexes = [];

            foreach (array_values($pairs) as $index => $pair) {
                if ($pair['violating'] > 0) {
                    $violatingIndexes[] = $index;
                    $lines[] = sprintf('    %s ==>|"%d (%d violating)"| %s', $pair['from'], $pair['total'], $pair['violating'], $pair['to']);
                } else {
                    $lines[] = sprintf('    %s -->|"%d"| %s', $pair['from'], $pair['total'], $pair['to']);
                }
            }

            if ($violatingIndexes !== []) {
                $lines[] = sprintf('    linkStyle %s stroke:%s', implode(',', $violatingIndexes), self::VIOLATING_STROKE);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function node(MermaidDiagram $diagram, string $leafName, ?string $groupName): string
    {
        $label = $groupName !== null && str_starts_with($leafName, $groupName.'_')
            ? substr($leafName, strlen($groupName) + 1)
            : $leafName;

        if (isset($diagram->externalLayers[$leafName])) {
            $node = sprintf('%s[[%s]]', $leafName, $label);
        } elseif ($label !== $leafName) {
            $node = sprintf('%s[%s]', $leafName, $label);
        } else {
            $node = $leafName;
        }

        return isset($diagram->emptyLayers[$leafName]) ? $node.':::empty' : $node;
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
