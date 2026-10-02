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

    public function testViolatingPairIsAThickRedArrowWithViolationCount(): void
    {
        [$graph, $ruleset, $violation] = $this->fixtureWithViolation();

        $output = (new MermaidReporter())->format([$violation], $graph, $ruleset);

        // Pairs render sorted: Domain|Infrastructure is edge 0, Infrastructure|Domain edge 1.
        self::assertStringContainsString("    Domain ==>|\"2 (1 violating)\"| Infrastructure\n", $output);
        self::assertStringContainsString("    Infrastructure -->|\"1\"| Domain\n", $output);
        self::assertStringContainsString("    linkStyle 0 stroke:#d73a49\n", $output);
    }

    public function testEveryViolatingEdgeIsListedInOneLinkStyle(): void
    {
        $domain = new Layer('Domain', ['App\Domain\**']);
        $application = new Layer('Application', ['App\Application\**']);
        $infrastructure = new Layer('Infrastructure', ['App\Infrastructure\**']);

        $graph = CodeGraph::fromElements(
            [
                new Element('App\Domain\Foo', ElementKind::ClassLike, 'Domain/Foo.php', 1),
                new Element('App\Application\Bar', ElementKind::ClassLike, 'Application/Bar.php', 1),
                new Element('App\Infrastructure\Baz', ElementKind::ClassLike, 'Infrastructure/Baz.php', 1),
            ],
            [
                new Dependency('App\Application\Bar', 'App\Infrastructure\Baz', DependencyKind::Extends, 'Application/Bar.php', 3),
                new Dependency('App\Domain\Foo', 'App\Application\Bar', DependencyKind::Extends, 'Domain/Foo.php', 3),
                new Dependency('App\Domain\Foo', 'App\Infrastructure\Baz', DependencyKind::Extends, 'Domain/Foo.php', 4),
            ],
        );

        $violations = [
            $this->violation('App\Application\Bar', 'App\Infrastructure\Baz', 'Application/Bar.php', 3),
            $this->violation('App\Domain\Foo', 'App\Infrastructure\Baz', 'Domain/Foo.php', 4),
        ];

        $output = (new MermaidReporter())->format($violations, $graph, new Ruleset([$domain, $application, $infrastructure]));

        // Sorted pairs: Application|Infrastructure (0), Domain|Application (1), Domain|Infrastructure (2).
        self::assertStringContainsString("    linkStyle 0,2 stroke:#d73a49\n", $output);
        self::assertSame(1, substr_count($output, 'linkStyle'));
    }

    public function testNoLinkStyleWithoutViolations(): void
    {
        [$graph, $ruleset] = $this->fixture();

        $output = (new MermaidReporter())->format([], $graph, $ruleset);

        self::assertStringNotContainsString('linkStyle', $output);
        self::assertStringNotContainsString('==>', $output);
    }

    public function testExternalLayerRendersAsADoubleBorderedNodeEvenWithoutEdges(): void
    {
        [$graph, $ruleset] = $this->fixtureWithExternalLayers();

        $output = (new MermaidReporter())->format([], $graph, $ruleset);

        self::assertStringContainsString("    Doctrine[[Doctrine]]\n", $output);
    }

    public function testDependencyOnAnExternalLayerIsAnEdge(): void
    {
        [$graph, $ruleset] = $this->fixtureWithExternalLayers();

        $output = (new MermaidReporter())->format([], $graph, $ruleset);

        self::assertStringContainsString("    SymfonyConsole[[SymfonyConsole]]\n", $output);
        self::assertStringContainsString('    Console -->|"2"| SymfonyConsole', $output);
    }

    public function testDependencyMatchingSeveralExternalLayersCountsTowardsEach(): void
    {
        [$graph, $ruleset] = $this->fixtureWithExternalLayers();

        $output = (new MermaidReporter())->format([], $graph, $ruleset);

        self::assertStringContainsString('    Console -->|"2"| SymfonyConsole', $output);
        self::assertStringContainsString('    Console -->|"3"| Symfony', $output);
    }

    public function testInternalElementTakesPriorityOverAnExternalPattern(): void
    {
        [$graph, $ruleset] = $this->fixtureWithExternalLayers();

        $output = (new MermaidReporter())->format([], $graph, $ruleset);

        // App\Domain\Foo also matches the `Everything` external layer (`App\**`).
        self::assertStringContainsString('    Console -->|"1"| Domain', $output);
        self::assertStringNotContainsString('-->|"1"| Everything', $output);
    }

    public function testViolationAgainstAnExternalLayerIsMarked(): void
    {
        [$graph, $ruleset] = $this->fixtureWithExternalLayers();
        $violation = $this->violation('App\Domain\Foo', 'Doctrine\ORM\EntityManager', 'Domain/Foo.php', 7);

        $output = (new MermaidReporter())->format([$violation], $graph, $ruleset);

        self::assertStringContainsString('    Domain ==>|"1 (1 violating)"| Doctrine', $output);
        self::assertStringContainsString('linkStyle', $output);
    }

    public function testDiagramLayerNamingAnExternalLayerShowsItsDependents(): void
    {
        [$graph, $ruleset] = $this->fixtureWithExternalLayers();

        $output = (new MermaidReporter(layerName: 'SymfonyConsole'))->format([], $graph, $ruleset);

        self::assertStringContainsString('    Console -->|"2"| SymfonyConsole', $output);
        self::assertStringNotContainsString('Doctrine', $output);
    }

    public function testLayerWithNoElementsHasADashedBorder(): void
    {
        $domain = new Layer('Domain', ['App\Domain\**']);
        $billing = new Layer('Billing', ['App\Billing\**']);

        $graph = CodeGraph::fromElements([new Element('App\Domain\Foo', ElementKind::ClassLike, 'Domain/Foo.php', 1)], []);

        $output = (new MermaidReporter())->format([], $graph, new Ruleset([$domain, $billing]));

        self::assertStringContainsString("    classDef empty stroke-dasharray: 5 5\n", $output);
        self::assertStringContainsString("    Billing:::empty\n", $output);
        self::assertStringContainsString("    Domain\n", $output);
    }

    public function testNoClassDefWhenEveryLayerHasElements(): void
    {
        [$graph, $ruleset] = $this->fixture();

        $output = (new MermaidReporter())->format([], $graph, $ruleset);

        self::assertStringNotContainsString('classDef', $output);
    }

    public function testExternalLayerIsNeverMarkedEmpty(): void
    {
        [$graph, $ruleset] = $this->fixtureWithExternalLayers();

        $output = (new MermaidReporter())->format([], $graph, $ruleset);

        self::assertStringNotContainsString('Doctrine[[Doctrine]]:::empty', $output);
        self::assertStringNotContainsString('classDef', $output);
    }

    public function testLeafInOverlappingGroupsGoesToTheFirstDeclaredGroupWithAShortLabel(): void
    {
        $runsDomain = new Layer('Runs_Domain', ['App\Runs\Domain\**']);
        $runsInfrastructure = new Layer('Runs_Infrastructure', ['App\Runs\Infrastructure\**']);
        $resultsDomain = new Layer('Results_Domain', ['App\Results\Domain\**']);
        $layers = [
            $runsDomain,
            $runsInfrastructure,
            $resultsDomain,
            new Layer('Runs', [], isGroup: true, members: [$runsDomain, $runsInfrastructure]),
            new Layer('Results', [], isGroup: true, members: [$resultsDomain]),
            new Layer('Domain', [], isGroup: true, members: [$runsDomain, $resultsDomain]),
            new Layer('Infrastructure', [], isGroup: true, members: [$runsInfrastructure]),
        ];

        $graph = CodeGraph::fromElements([
            new Element('App\Runs\Domain\Run', ElementKind::ClassLike, 'Run.php', 1),
            new Element('App\Runs\Infrastructure\RunRepository', ElementKind::ClassLike, 'RunRepository.php', 1),
            new Element('App\Results\Domain\Result', ElementKind::ClassLike, 'Result.php', 1),
        ], []);

        $output = (new MermaidReporter())->format([], $graph, new Ruleset($layers));

        self::assertStringContainsString(
            "    subgraph Runs\n        Runs_Domain[Domain]\n        Runs_Infrastructure[Infrastructure]\n    end\n"
            ."    subgraph Results\n        Results_Domain[Domain]\n    end\n",
            $output,
        );
        self::assertStringNotContainsString('subgraph Domain', $output);
        self::assertStringNotContainsString('subgraph Infrastructure', $output);
    }

    public function testLaterGroupKeepsOnlyItsUnclaimedLeaves(): void
    {
        $a = new Layer('A', ['App\A\**']);
        $b = new Layer('B', ['App\B\**']);
        $layers = [
            $a,
            $b,
            new Layer('First', [], isGroup: true, members: [$a]),
            new Layer('Second', [], isGroup: true, members: [$a, $b]),
        ];

        $graph = CodeGraph::fromElements([
            new Element('App\A\Foo', ElementKind::ClassLike, 'A/Foo.php', 1),
            new Element('App\B\Bar', ElementKind::ClassLike, 'B/Bar.php', 1),
        ], []);

        $output = (new MermaidReporter())->format([], $graph, new Ruleset($layers));

        self::assertStringContainsString("    subgraph First\n        A\n    end\n    subgraph Second\n        B\n    end\n", $output);
    }

    public function testHandWrittenLeafNameIsNotShortenedInsideItsGroup(): void
    {
        $runsDomain = new Layer('RunsDomain', ['App\Runs\Domain\**']);
        $layers = [$runsDomain, new Layer('Runs', [], isGroup: true, members: [$runsDomain])];

        $graph = CodeGraph::fromElements([new Element('App\Runs\Domain\Run', ElementKind::ClassLike, 'Run.php', 1)], []);

        $output = (new MermaidReporter())->format([], $graph, new Ruleset($layers));

        self::assertStringContainsString("    subgraph Runs\n        RunsDomain\n    end\n", $output);
    }

    public function testViolationsOnlyScopeDropsCompliantPairs(): void
    {
        [$graph, $ruleset, $violation] = $this->fixtureWithViolation();

        $output = (new MermaidReporter(violationsOnly: true))->format([$violation], $graph, $ruleset);

        self::assertStringContainsString('==>|"2 (1 violating)"|', $output);
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

        // No elements, so every layer also renders as empty.
        self::assertStringContainsString("    Layer0:::empty\n", $output);
        self::assertStringContainsString("    Layer40:::empty\n", $output);
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
     * `Console` depends on two Symfony Console classes, one other Symfony class,
     * and an internal class that the `Everything` external pattern also matches.
     * Nothing depends on Doctrine.
     *
     * @return array{0: CodeGraph, 1: Ruleset}
     */
    private function fixtureWithExternalLayers(): array
    {
        $domain = new Layer('Domain', ['App\Domain\**']);
        $console = new Layer('Console', ['App\Console\**']);
        $symfonyConsole = new Layer('SymfonyConsole', ['Symfony\Component\Console\**'], isExternal: true);
        $symfony = new Layer('Symfony', ['Symfony\**'], isExternal: true);
        $doctrine = new Layer('Doctrine', ['Doctrine\**'], isExternal: true);
        $everything = new Layer('Everything', ['App\**'], isExternal: true);

        $graph = CodeGraph::fromElements(
            [
                new Element('App\Domain\Foo', ElementKind::ClassLike, 'Domain/Foo.php', 1),
                new Element('App\Console\RunCommand', ElementKind::ClassLike, 'Console/RunCommand.php', 1),
            ],
            [
                new Dependency('App\Console\RunCommand', 'Symfony\Component\Console\Command\Command', DependencyKind::Extends, 'Console/RunCommand.php', 3),
                new Dependency('App\Console\RunCommand', 'Symfony\Component\Console\Input\InputInterface', DependencyKind::ParamType, 'Console/RunCommand.php', 5),
                new Dependency('App\Console\RunCommand', 'Symfony\Component\Yaml\Yaml', DependencyKind::StaticCall, 'Console/RunCommand.php', 6),
                new Dependency('App\Console\RunCommand', 'App\Domain\Foo', DependencyKind::Instantiate, 'Console/RunCommand.php', 7),
                new Dependency('App\Domain\Foo', 'Doctrine\ORM\EntityManager', DependencyKind::ParamType, 'Domain/Foo.php', 7),
            ],
        );

        return [$graph, new Ruleset([$domain, $console, $symfonyConsole, $symfony, $doctrine, $everything])];
    }

    private function violation(string $from, string $to, string $file, int $line): Violation
    {
        return new Violation(
            rule: 'rule',
            fromElement: $from,
            toElement: $to,
            dependencyKind: DependencyKind::Extends,
            file: $file,
            line: $line,
            message: 'message',
        );
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
