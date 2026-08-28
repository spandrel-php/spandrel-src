<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Reporting;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\CodeGraph;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;
use Spandrel\Spandrel\Reporting\MermaidDiagramTooLargeException;
use Spandrel\Spandrel\Reporting\MermaidReporter;
use Spandrel\Spandrel\RuleEngine\Violation;
use Spandrel\Spandrel\Ruleset\Layer;
use Spandrel\Spandrel\Ruleset\Ruleset;

final class MermaidReporterTest extends TestCase
{
    public function testBareNodeForALayerWithNoEdges(): void
    {
        [$graph, $ruleset] = $this->fixture();

        $output = (new MermaidReporter())->format([], $graph, $ruleset);

        self::assertStringContainsString("    Shared\n", $output);
    }

    public function testGroupRendersAsASubgraphContainingItsMembers(): void
    {
        [$graph, $ruleset] = $this->fixture();

        $output = (new MermaidReporter())->format([], $graph, $ruleset);

        self::assertStringContainsString("    subgraph IO\n        Infrastructure\n    end\n", $output);
        // Infrastructure is claimed by the IO subgraph, so it must not also appear as its
        // own bare top-level node (a line consisting of exactly 4 spaces + the name).
        self::assertNotContains('    Infrastructure', explode("\n", $output));
    }

    public function testCompliantPairIsASolidArrowWithCount(): void
    {
        [$graph, $ruleset] = $this->fixture();

        $output = (new MermaidReporter())->format([], $graph, $ruleset);

        self::assertStringContainsString('Infrastructure -->|"1"| Domain', $output);
    }

    public function testViolatingPairIsADottedArrowWithViolationCount(): void
    {
        [$graph, $ruleset, $violation] = $this->fixtureWithViolation();

        $output = (new MermaidReporter())->format([$violation], $graph, $ruleset);

        self::assertStringContainsString('Domain -.->|"2 (1 violating)"| Infrastructure', $output);
    }

    public function testViolationsOnlyScopeDropsCompliantPairs(): void
    {
        [$graph, $ruleset, $violation] = $this->fixtureWithViolation();

        $output = (new MermaidReporter(violationsOnly: true))->format([$violation], $graph, $ruleset);

        self::assertStringContainsString('-.->|"2 (1 violating)"|', $output);
        self::assertStringNotContainsString('-->|"1"|', $output);
    }

    public function testDiagramLayerScopesToTheNamedLeafsImmediateNeighbors(): void
    {
        [$graph, $ruleset] = $this->fixture();

        $output = (new MermaidReporter(layerName: 'Domain'))->format([], $graph, $ruleset);

        self::assertStringContainsString('Domain', $output);
        self::assertStringContainsString('Infrastructure -->|"1"| Domain', $output);
        self::assertStringNotContainsString('Shared', $output);
    }

    public function testDiagramLayerAlwaysShowsTheNamedLayerEvenWithNoEdges(): void
    {
        [$graph, $ruleset] = $this->fixture();

        $output = (new MermaidReporter(layerName: 'Shared'))->format([], $graph, $ruleset);

        self::assertStringContainsString("    Shared\n", $output);
        self::assertStringNotContainsString('Domain', $output);
        self::assertStringNotContainsString('Infrastructure', $output);
    }

    public function testDiagramLayerNamingAGroupExpandsToItsMembers(): void
    {
        [$graph, $ruleset] = $this->fixture();

        $output = (new MermaidReporter(layerName: 'IO'))->format([], $graph, $ruleset);

        self::assertStringContainsString("    subgraph IO\n        Infrastructure\n    end\n", $output);
        self::assertStringContainsString('Infrastructure -->|"1"| Domain', $output);
        self::assertStringNotContainsString('Shared', $output);
    }

    public function testDiagramLayerOnlyKeepsRelevantGroupMembers(): void
    {
        $domain = new Element('App\Domain\Foo', ElementKind::ClassLike, 'Domain/Foo.php', 1);
        $infra = new Element('App\Infrastructure\Bar', ElementKind::ClassLike, 'Infrastructure/Bar.php', 1);
        $cache = new Element('App\Cache\Baz', ElementKind::ClassLike, 'Cache/Baz.php', 1);

        $dependency = new Dependency('App\Infrastructure\Bar', 'App\Domain\Foo', DependencyKind::Extends, 'Infrastructure/Bar.php', 5);

        $graph = CodeGraph::fromElements([$domain, $infra, $cache], [$dependency]);

        $domainLayer = new Layer('Domain', ['App\Domain\**']);
        $infraLayer = new Layer('Infrastructure', ['App\Infrastructure\**']);
        $cacheLayer = new Layer('Cache', ['App\Cache\**']);
        $ioLayer = new Layer('IO', ['App\Infrastructure\**', 'App\Cache\**'], isGroup: true, members: [$infraLayer, $cacheLayer]);

        $ruleset = new Ruleset([$domainLayer, $infraLayer, $cacheLayer, $ioLayer]);

        $output = (new MermaidReporter(layerName: 'Domain'))->format([], $graph, $ruleset);

        self::assertStringContainsString("    subgraph IO\n        Infrastructure\n    end\n", $output);
        self::assertStringNotContainsString('Cache', $output);
    }

    public function testThrowsWhenNodeCountExceedsTheReadabilityLimit(): void
    {
        $ruleset = new Ruleset($this->manyLayers(41));
        $graph = CodeGraph::fromElements([], []);

        $this->expectException(MermaidDiagramTooLargeException::class);
        $this->expectExceptionMessage('41 layers');

        (new MermaidReporter())->format([], $graph, $ruleset);
    }

    public function testForceRendersDespiteExceedingTheNodeLimit(): void
    {
        $ruleset = new Ruleset($this->manyLayers(41));
        $graph = CodeGraph::fromElements([], []);

        $output = (new MermaidReporter(force: true))->format([], $graph, $ruleset);

        self::assertStringContainsString("    Layer0\n", $output);
        self::assertStringContainsString("    Layer40\n", $output);
    }

    public function testThrowsWhenEdgeCountExceedsTheReadabilityLimit(): void
    {
        [$graph, $ruleset] = $this->denseBipartiteFixture();

        $this->expectException(MermaidDiagramTooLargeException::class);
        $this->expectExceptionMessage('81 edges');

        (new MermaidReporter())->format([], $graph, $ruleset);
    }

    public function testStaysUnderTheLimitDoesNotThrow(): void
    {
        [$graph, $ruleset] = $this->fixture();

        $output = (new MermaidReporter())->format([], $graph, $ruleset);

        self::assertStringStartsWith('flowchart LR', $output);
    }

    /**
     * @return array{0: CodeGraph, 1: Ruleset}
     */
    private function fixture(): array
    {
        $domainFoo = new Element('App\Domain\Foo', ElementKind::ClassLike, 'Domain/Foo.php', 1);
        $infraBar = new Element('App\Infrastructure\Bar', ElementKind::ClassLike, 'Infrastructure/Bar.php', 1);
        $sharedBaz = new Element('App\Shared\Baz', ElementKind::ClassLike, 'Shared/Baz.php', 1);

        $dependency = new Dependency('App\Infrastructure\Bar', 'App\Domain\Foo', DependencyKind::Extends, 'Infrastructure/Bar.php', 5);

        $graph = CodeGraph::fromElements([$domainFoo, $infraBar, $sharedBaz], [$dependency]);
        $ruleset = new Ruleset($this->layers());

        return [$graph, $ruleset];
    }

    /**
     * @return array{0: CodeGraph, 1: Ruleset, 2: Violation}
     */
    private function fixtureWithViolation(): array
    {
        $domainFoo = new Element('App\Domain\Foo', ElementKind::ClassLike, 'Domain/Foo.php', 1);
        $infraBar = new Element('App\Infrastructure\Bar', ElementKind::ClassLike, 'Infrastructure/Bar.php', 1);
        $infraBaz = new Element('App\Infrastructure\Baz', ElementKind::ClassLike, 'Infrastructure/Baz.php', 1);

        $violating = new Dependency('App\Domain\Foo', 'App\Infrastructure\Bar', DependencyKind::Extends, 'Domain/Foo.php', 9);
        $compliant = new Dependency('App\Domain\Foo', 'App\Infrastructure\Baz', DependencyKind::Extends, 'Domain/Foo.php', 10);
        $unrelatedCompliantPair = new Dependency('App\Infrastructure\Bar', 'App\Domain\Foo', DependencyKind::Extends, 'Infrastructure/Bar.php', 3);

        $graph = CodeGraph::fromElements([$domainFoo, $infraBar, $infraBaz], [$violating, $compliant, $unrelatedCompliantPair]);
        $ruleset = new Ruleset($this->layers());

        $violation = new Violation(
            rule: '`Domain` must not depend on `Infrastructure`',
            fromElement: 'App\Domain\Foo',
            toElement: 'App\Infrastructure\Bar',
            dependencyKind: DependencyKind::Extends,
            file: 'Domain/Foo.php',
            line: 9,
            message: 'message',
        );

        return [$graph, $ruleset, $violation];
    }

    /**
     * @return Layer[]
     */
    private function layers(): array
    {
        $domain = new Layer('Domain', ['App\Domain\**']);
        $infrastructure = new Layer('Infrastructure', ['App\Infrastructure\**']);
        $shared = new Layer('Shared', ['App\Shared\**']);
        $io = new Layer('IO', ['App\Infrastructure\**'], isGroup: true, members: [$infrastructure]);

        return [$domain, $infrastructure, $shared, $io];
    }

    /**
     * @return Layer[]
     */
    private function manyLayers(int $count): array
    {
        $layers = [];

        for ($i = 0; $i < $count; $i++) {
            $layers[] = new Layer("Layer{$i}", ["App\\Layer{$i}\\**"]);
        }

        return $layers;
    }

    /**
     * 9 "from" leaf layers × 9 "to" leaf layers, one dependency per
     * combination — 18 layers (well under the node limit) but 81 layer
     * pairs (over the edge limit), isolating the edge-count check from
     * the node-count one.
     *
     * @return array{0: CodeGraph, 1: Ruleset}
     */
    private function denseBipartiteFixture(): array
    {
        $layers = [];
        $elements = [];
        $dependencies = [];

        for ($i = 0; $i < 9; $i++) {
            $fromFqcn = "App\\From{$i}\\Foo";
            $layers[] = new Layer("From{$i}", ["App\\From{$i}\\**"]);
            $elements[] = new Element($fromFqcn, ElementKind::ClassLike, "From{$i}/Foo.php", 1);

            for ($j = 0; $j < 9; $j++) {
                $toFqcn = "App\\To{$j}\\Bar";
                $dependencies[] = new Dependency($fromFqcn, $toFqcn, DependencyKind::Extends, "From{$i}/Foo.php", 1);
            }
        }

        for ($j = 0; $j < 9; $j++) {
            $toFqcn = "App\\To{$j}\\Bar";
            $layers[] = new Layer("To{$j}", ["App\\To{$j}\\**"]);
            $elements[] = new Element($toFqcn, ElementKind::ClassLike, "To{$j}/Bar.php", 1);
        }

        $graph = CodeGraph::fromElements($elements, $dependencies);
        $ruleset = new Ruleset($layers);

        return [$graph, $ruleset];
    }
}
