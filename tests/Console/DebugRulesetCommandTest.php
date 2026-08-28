<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Console;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Console\DebugRulesetCommand;
use Spandrel\Spandrel\Console\RulesetLoader;
use Spandrel\Spandrel\Console\SourceGraphBuilder;
use Spandrel\Spandrel\Loader\Loader;
use Spandrel\Spandrel\Parser\Parser;
use Spandrel\Spandrel\Ruleset\RulesetParser;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DebugRulesetCommandTest extends TestCase
{
    public function testRendersMetaSentencesWhenDeclared(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture-meta-strict-elements.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringStartsWith(
            "## Meta\n\n- Any class not in a layer violates rules.\n\n## Layers\n",
            $display,
        );
    }

    public function testOmitsMetaSectionWhenNoPolicyIsDeclared(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('## Meta', $tester->getDisplay());
    }

    public function testMultipleRulesetOptionsMergeIntoOneRun(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => [
                $fixtures.'/architecture-split-layers.md',
                $fixtures.'/architecture-split-rules.md',
            ],
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('**Domain**', $display);
        self::assertStringContainsString('**Infrastructure**', $display);
        self::assertStringContainsString('must not depend on', $display);
    }

    public function testExpandsListsNullaryPredicatesAndUnconstrainedLayers(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture-full.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        self::assertSame(
            <<<'OUTPUT'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Infrastructure**: `App\Infrastructure\**`
            - **Shared**: `App\Shared\**`

            ## Rules

            - `Domain` must not depend on `Infrastructure`
            - `Domain` must not depend on `Shared`
            - `Infrastructure` may only depend on `Domain`
            - `Shared` may depend on anything

            OUTPUT,
            $tester->getDisplay(),
        );
    }

    public function testRendersMixedDeclaredLayerAndInlinePatternSubjectRules(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture-inline-pattern.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        self::assertSame(
            <<<'OUTPUT'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` must not depend on `App\Vendor\**`
            - `App\Legacy\**` may only depend on `Domain` except `App\Legacy\Excluded`

            OUTPUT,
            $tester->getDisplay(),
        );
    }

    public function testRendersElementKindFiltersOnBothSubjectAndObject(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture-kind-filter.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        self::assertSame(
            <<<'OUTPUT'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` may only depend on interfaces in `Symfony\**`
            - classes and enums in `App\Legacy\**` must not depend on `Domain`

            OUTPUT,
            $tester->getDisplay(),
        );
    }

    public function testSilentLayerProducesNoRuleLines(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $tester->execute([
            '--ruleset' => $fixtures.'/architecture.md',
        ]);

        // Layers section always lists declared layers; only the Rules section
        // should stay silent for a layer with no rule bullets at all.
        $display = $tester->getDisplay();
        $rulesSection = substr($display, (int) strpos($display, '## Rules'));

        self::assertStringNotContainsString('Domain', $rulesSection);
        self::assertStringNotContainsString('Infrastructure', $rulesSection);
    }

    public function testRendersKindScopedRulesAndReservedTargets(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture-kind-scoped.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        self::assertSame(
            <<<'OUTPUT'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Util**: `App\Util\**`

            ## Rules

            - `Domain` must not call functions defined in `Util`
            - `Domain` must not call functions defined in core functions
            - `Domain` must not instantiate objects
            - `Domain` may only throw `Util`
            - `Domain` may only throw core classes

            OUTPUT,
            $tester->getDisplay(),
        );
    }

    public function testRendersSubtypesOfUnchanged(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture-subtypes.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        self::assertSame(
            <<<'OUTPUT'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` may only throw subtypes of `App\Exception\Base`

            OUTPUT,
            $tester->getDisplay(),
        );
    }

    public function testWithoutSourceShowsUnexpandedPlaceholderBullet(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        // An explicit --config with no source.paths, rather than relying on
        // ConfigLoader's cwd-relative default: the test process's cwd is the
        // real app root, whose own spandrel.yaml declares real source.paths
        // and would otherwise supply real Elements here.
        $configPath = tempnam(sys_get_temp_dir(), 'spandrel-yaml-');
        self::assertIsString($configPath);
        file_put_contents($configPath, "ruleset: does-not-matter.md\n");

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                '--config' => $configPath,
                '--ruleset' => $fixtures.'/architecture-placeholder.md',
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('- `App\{Module}\**`', $tester->getDisplay());
        } finally {
            unlink($configPath);
        }
    }

    public function testWithSourceExpandsPlaceholderBullet(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-placeholder.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('- **Domain**: `App\Domain\**`', $display);
        self::assertStringContainsString('- **Infrastructure**: `App\Infrastructure\**`', $display);
        self::assertStringContainsString('- **Shared**: `App\Shared\**`', $display);
        self::assertStringNotContainsString('{Module}', $display);
    }

    public function testCacheDirCreatesACacheFile(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $cacheDir = sys_get_temp_dir().'/spandrel-debugruleset-cache-'.uniqid();

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--cache-dir' => $cacheDir,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertFileExists($cacheDir.'/cache.data');
        } finally {
            @unlink($cacheDir.'/cache.data');
            @rmdir($cacheDir);
        }
    }

    public function testFailsOnMalformedRuleset(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture-malformed.md',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('could not parse rule bullet', $tester->getDisplay());
    }

    public function testFailsWithMissingRulesetFile(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => '/does/not/exist.md',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testConfigSuppliesRuleset(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $configPath = tempnam(sys_get_temp_dir(), 'spandrel-yaml-');
        self::assertIsString($configPath);
        file_put_contents($configPath, sprintf("ruleset: %s\n", $fixtures.'/architecture.md'));

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute(['--config' => $configPath]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('**Domain**', $tester->getDisplay());
        } finally {
            unlink($configPath);
        }
    }

    public function testFailsWhenExplicitConfigFileIsMissing(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--config' => '/does/not/exist.yaml']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Config file not found', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new DebugRulesetCommand(
            new ConfigLoader(),
            new RulesetLoader(new RulesetParser()),
            new SourceGraphBuilder(new Loader(), new Parser()),
        ));

        return new CommandTester($application->find('debug:ruleset'));
    }
}
