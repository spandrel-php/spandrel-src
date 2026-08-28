<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Console;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Console\DebugLayersCommand;
use Spandrel\Spandrel\Console\RulesetLoader;
use Spandrel\Spandrel\Console\SourceGraphBuilder;
use Spandrel\Spandrel\Loader\Loader;
use Spandrel\Spandrel\Parser\Parser;
use Spandrel\Spandrel\Ruleset\RulesetParser;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DebugLayersCommandTest extends TestCase
{
    public function testMultipleRulesetOptionsMergeIntoOneRun(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'path' => $fixtures.'/src',
            '--ruleset' => [
                $fixtures.'/architecture-split-layers.md',
                $fixtures.'/architecture-split-rules.md',
            ],
        ]);

        self::assertSame(0, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Domain', $display);
        self::assertStringContainsString('Infrastructure', $display);
    }

    public function testShowsLayerCountsAndUnmatchedBucket(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'path' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
        ]);

        self::assertSame(0, $exitCode);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Domain', $display);
        self::assertStringContainsString('Infrastructure', $display);
        self::assertStringContainsString('Unmatched: 1', $display);
    }

    public function testMarksAnExternalLayerAndShowsItMatchingZeroElements(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'path' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-external-layer.md',
        ]);

        self::assertSame(0, $exitCode);
        self::assertMatchesRegularExpression('/SymfonyConsole \(external\)\s+.*\s+0/', $tester->getDisplay());
    }

    public function testCacheDirCreatesACacheFile(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $cacheDir = sys_get_temp_dir().'/spandrel-debuglayers-cache-'.uniqid();

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                'path' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--cache-dir' => $cacheDir,
            ]);

            self::assertSame(0, $exitCode);
            self::assertFileExists($cacheDir.'/cache.data');
        } finally {
            @unlink($cacheDir.'/cache.data');
            @rmdir($cacheDir);
        }
    }

    public function testShowsGroupLayerAggregateAndExceptNarrowedCount(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'path' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-groups.md',
        ]);

        self::assertSame(0, $exitCode);

        $display = $tester->getDisplay();

        // Domain's own except doesn't carve out anything in this fixture, so it's
        // unaffected (2 elements); IO groups Infrastructure and must show the same
        // aggregate count Infrastructure itself does (1).
        self::assertMatchesRegularExpression('/Domain\s+.*\s+2/', $display);
        self::assertMatchesRegularExpression('/Infrastructure\s+.*\s+1/', $display);
        self::assertMatchesRegularExpression('/IO\s+.*\s+1/', $display);
    }

    public function testFailsWithMissingRulesetFile(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute([
            'path' => __DIR__.'/../Fixtures/DemoApp/src',
            '--ruleset' => '/does/not/exist.md',
        ]);

        self::assertSame(1, $exitCode);
    }

    public function testFailsWithNoPathAndNoConfig(): void
    {
        // Explicit --config with no source.paths, rather than relying on no
        // spandrel.yaml existing at the process CWD (this repo's own
        // spandrel.yaml would otherwise make this test CWD-dependent).
        $configPath = tempnam(sys_get_temp_dir(), 'spandrel-yaml-');
        self::assertIsString($configPath);
        file_put_contents($configPath, "ruleset: architecture.md\n");

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                '--ruleset' => __DIR__.'/../Fixtures/DemoApp/architecture.md',
                '--config' => $configPath,
            ]);

            self::assertSame(1, $exitCode);
            self::assertStringContainsString('No source path given', $tester->getDisplay());
        } finally {
            unlink($configPath);
        }
    }

    public function testConfigSuppliesPathAndRuleset(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $configPath = $this->writeTempConfig([$fixtures.'/src'], $fixtures.'/architecture.md');

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute(['--config' => $configPath]);

            self::assertSame(0, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('Domain', $display);
            self::assertStringContainsString('Unmatched: 1', $display);
        } finally {
            unlink($configPath);
        }
    }

    public function testFailsWhenExplicitConfigFileIsMissing(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute([
            'path' => __DIR__.'/../Fixtures/DemoApp/src',
            '--config' => '/does/not/exist.yaml',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Config file not found', $tester->getDisplay());
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
        $application->addCommand(new DebugLayersCommand(
            new ConfigLoader(),
            new RulesetLoader(new RulesetParser()),
            new SourceGraphBuilder(new Loader(), new Parser()),
        ));

        return new CommandTester($application->find('debug:layers'));
    }
}
