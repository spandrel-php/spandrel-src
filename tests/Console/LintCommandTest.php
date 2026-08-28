<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Console;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Console\LintCommand;
use Spandrel\Spandrel\Console\RulesetLoader;
use Spandrel\Spandrel\Console\SourceGraphBuilder;
use Spandrel\Spandrel\Loader\Loader;
use Spandrel\Spandrel\Parser\Parser;
use Spandrel\Spandrel\Ruleset\RulesetParser;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class LintCommandTest extends TestCase
{
    public function testValidRulesetWithoutPathsSucceeds(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        // Explicit --config with no source.paths, rather than relying on no
        // spandrel.yaml existing at the process CWD (this repo's own
        // spandrel.yaml would otherwise make this test CWD-dependent
        // and silently exercise the Code Graph checks instead of skipping
        // them).
        $configPath = tempnam(sys_get_temp_dir(), 'spandrel-yaml-');
        self::assertIsString($configPath);
        file_put_contents($configPath, "ruleset: architecture.md\n");

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                '--ruleset' => $fixtures.'/architecture.md',
                '--config' => $configPath,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('is valid', $display);
            self::assertStringNotContainsString('matched zero elements', $display);
        } finally {
            unlink($configPath);
        }
    }

    public function testCacheDirCreatesACacheFile(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $cacheDir = sys_get_temp_dir().'/spandrel-lint-cache-'.uniqid();

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

    public function testNoCacheSuppressesAConfiguredCacheDirectory(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $cacheDir = sys_get_temp_dir().'/spandrel-lint-cache-'.uniqid();
        $configPath = tempnam(sys_get_temp_dir(), 'spandrel-yaml-');
        self::assertIsString($configPath);
        file_put_contents($configPath, "cache:\n    directory: $cacheDir\n");

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--config' => $configPath,
                '--no-cache' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertFileDoesNotExist($cacheDir.'/cache.data');
        } finally {
            unlink($configPath);
        }
    }

    public function testValidRulesetWithPathsSucceedsAndReportsNoWarnings(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('is valid', $display);
        self::assertStringNotContainsString('matched zero elements', $display);
    }

    public function testWarnsOnLayerMatchingZeroElements(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-empty-layer.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Layer "Unused" matched zero elements', $tester->getDisplay());
    }

    public function testExternalLayerNeverWarnsAboutMatchingZeroElements(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-external-layer.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('matched zero elements', $tester->getDisplay());
    }

    public function testExternalLayerUsedOnlyAsARuleObjectDoesNotTripStrictLayers(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture-external-layer.md',
            '--strict-layers' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('is not used in any rule', $tester->getDisplay());
    }

    public function testFailsOnAmbiguousLayerMatch(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-ambiguous.md',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('matches more than one layer', $tester->getDisplay());
    }

    public function testFailsOnMalformedRuleset(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture-malformed.md',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('could not parse rule bullet', $tester->getDisplay());
    }

    public function testFailsWithMissingRulesetFile(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => '/does/not/exist.md',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testConfigSuppliesPathsAndRuleset(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $configPath = $this->writeTempConfig([$fixtures.'/src'], $fixtures.'/architecture.md');

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute(['--config' => $configPath]);

            self::assertSame(Command::SUCCESS, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('is valid', $display);
            self::assertStringNotContainsString('matched zero elements', $display);
        } finally {
            unlink($configPath);
        }
    }

    public function testCliArgumentsOverrideConfig(): void
    {
        $demoApp = __DIR__.'/../Fixtures/DemoApp';
        $configPath = $this->writeTempConfig([$demoApp.'/src'], $demoApp.'/architecture.md');

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                '--ruleset' => $demoApp.'/architecture-empty-layer.md',
                '--config' => $configPath,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('Layer "Unused" matched zero elements', $tester->getDisplay());
        } finally {
            unlink($configPath);
        }
    }

    public function testFailsWhenExplicitConfigFileIsMissing(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--config' => '/does/not/exist.yaml']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Config file not found', $tester->getDisplay());
    }

    public function testMultipleRulesetOptionsMergeIntoOneValidRun(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => [
                $fixtures.'/architecture-split-layers.md',
                $fixtures.'/architecture-split-rules.md',
            ],
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('[OK]', $tester->getDisplay());
    }

    public function testStrictLayersFailsWithoutAnyRulesAndNeedsNoPaths(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        // Explicit --config with no source.paths, rather than relying on no
        // spandrel.yaml existing at the process CWD (this repo's own
        // spandrel.yaml would otherwise make this test CWD-dependent) —
        // deliberately proving --strict-layers works with zero source.
        $configPath = tempnam(sys_get_temp_dir(), 'spandrel-yaml-');
        self::assertIsString($configPath);
        file_put_contents($configPath, "ruleset: architecture.md\n");

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                '--ruleset' => $fixtures.'/architecture.md',
                '--config' => $configPath,
                '--strict-layers' => true,
            ]);

            self::assertSame(Command::INVALID, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('Domain', $display);
            self::assertStringContainsString('Infrastructure', $display);
        } finally {
            unlink($configPath);
        }
    }

    public function testStrictLayersFailsOnAnUncoveredGroupLayer(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture-groups.md',
            '--strict-layers' => true,
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('IO', $tester->getDisplay());
    }

    public function testStrictLayersSucceedsWhenEveryLayerIsCovered(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--ruleset' => $fixtures.'/architecture-full.md',
            '--strict-layers' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('is valid', $tester->getDisplay());
    }

    public function testMetaStrictLayersFailsWithNoCliFlag(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        // Explicit --config with no source.paths, same isolation reason as
        // testStrictLayersFailsWithoutAnyRulesAndNeedsNoPaths above.
        $configPath = tempnam(sys_get_temp_dir(), 'spandrel-yaml-');
        self::assertIsString($configPath);
        file_put_contents($configPath, "ruleset: architecture.md\n");

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                '--ruleset' => $fixtures.'/architecture-meta-strict-layers.md',
                '--config' => $configPath,
            ]);

            self::assertSame(Command::INVALID, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('Domain', $display);
            self::assertStringContainsString('Infrastructure', $display);
        } finally {
            unlink($configPath);
        }
    }

    public function testNoStrictLayersOverridesAMetaDeclaredPolicyOff(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $configPath = tempnam(sys_get_temp_dir(), 'spandrel-yaml-');
        self::assertIsString($configPath);
        file_put_contents($configPath, "ruleset: architecture.md\n");

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                '--ruleset' => $fixtures.'/architecture-meta-strict-layers.md',
                '--config' => $configPath,
                '--no-strict-layers' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('is valid', $tester->getDisplay());
        } finally {
            unlink($configPath);
        }
    }

    /**
     * @param string[] $sourcePaths
     */
    private function writeTempConfig(array $sourcePaths, string $ruleset): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spandrel-yaml-');
        self::assertIsString($path);

        $paths = implode("\n", array_map(static fn (string $p): string => "        - $p", $sourcePaths));
        file_put_contents($path, "source:\n    paths:\n$paths\nruleset: $ruleset\n");

        return $path;
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new LintCommand(
            new ConfigLoader(),
            new RulesetLoader(new RulesetParser()),
            new SourceGraphBuilder(new Loader(), new Parser()),
        ));

        return new CommandTester($application->find('lint'));
    }
}
