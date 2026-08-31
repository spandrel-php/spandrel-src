<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Console;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Console\AnalyseCommand;
use Spandrel\Spandrel\Console\RulesetLoader;
use Spandrel\Spandrel\Console\SourceGraphBuilder;
use Spandrel\Spandrel\Loader\Loader;
use Spandrel\Spandrel\Parser\Parser;
use Spandrel\Spandrel\RuleEngine\RuleEngine;
use Spandrel\Spandrel\Ruleset\RulesetParser;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyseCommandTest extends TestCase
{
    public function testCleanCodebaseSucceeds(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No violations found', $tester->getDisplay());
    }

    public function testCacheDirCreatesACacheFileAndStillSucceeds(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $cacheDir = sys_get_temp_dir().'/spandrel-analyse-cache-'.uniqid();

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--cache-dir' => $cacheDir,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertFileExists($cacheDir.'/cache.data');

            // Second run should hit the cache we just wrote and still succeed.
            $exitCode = $this->tester()->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--cache-dir' => $cacheDir,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
        } finally {
            @unlink($cacheDir.'/cache.data');
            @rmdir($cacheDir);
        }
    }

    public function testNoCacheSuppressesAConfiguredCacheDirectory(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $cacheDir = sys_get_temp_dir().'/spandrel-analyse-cache-'.uniqid();
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

    public function testReportsViolationsAndExitsWithFailure(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Baz.php', $display);
        self::assertStringContainsString('Line 9', $display);
        self::assertStringContainsString(
            'App\Domain\Baz (Domain) must not depend on App\Infrastructure\Bar (Infrastructure) via extends',
            $display,
        );
        self::assertStringContainsString('1 violation across 1 file', $display);
    }

    public function testVerboseOutputIncludesTheViolatedRuleText(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';

        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
            ],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE],
        );

        self::assertStringContainsString('Rule: `Domain` must not depend on `Infrastructure`', $tester->getDisplay());
    }

    public function testGenerateBaselineWritesFileAndSucceedsDespiteViolations(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';
        $baselinePath = tempnam(sys_get_temp_dir(), 'spandrel-baseline-');
        self::assertIsString($baselinePath);
        unlink($baselinePath);

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--generate-baseline' => true,
                '--baseline' => $baselinePath,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertFileExists($baselinePath);

            /** @var array{violations: array<int, array<string, mixed>>} $written */
            $written = json_decode((string) file_get_contents($baselinePath), true);
            self::assertCount(1, $written['violations']);
            self::assertSame('App\Domain\Baz', $written['violations'][0]['from']);
        } finally {
            @unlink($baselinePath);
        }
    }

    public function testBaselineSuppressesAKnownViolation(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';
        $baselinePath = tempnam(sys_get_temp_dir(), 'spandrel-baseline-');
        self::assertIsString($baselinePath);

        try {
            $this->tester()->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--generate-baseline' => true,
                '--baseline' => $baselinePath,
            ]);

            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--baseline' => $baselinePath,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('No new violations found', $tester->getDisplay());
            self::assertStringContainsString('(1 baselined)', $tester->getDisplay());
        } finally {
            unlink($baselinePath);
        }
    }

    public function testBaselineDoesNotSuppressADifferentViolation(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';
        $baselinePath = tempnam(sys_get_temp_dir(), 'spandrel-baseline-');
        self::assertIsString($baselinePath);
        file_put_contents($baselinePath, json_encode([
            'violations' => [
                ['from' => 'App\Some\Other', 'to' => 'App\Unrelated\Thing', 'kind' => 'extends'],
            ],
        ]));

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--baseline' => $baselinePath,
            ]);

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('1 violation across 1 file', $tester->getDisplay());
        } finally {
            unlink($baselinePath);
        }
    }

    public function testNoBaselineForcesFullReportingEvenWithConfiguredBaseline(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';
        $baselinePath = tempnam(sys_get_temp_dir(), 'spandrel-baseline-');
        self::assertIsString($baselinePath);

        try {
            $this->tester()->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--generate-baseline' => true,
                '--baseline' => $baselinePath,
            ]);

            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--baseline' => $baselinePath,
                '--no-baseline' => true,
            ]);

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('1 violation across 1 file', $tester->getDisplay());
        } finally {
            unlink($baselinePath);
        }
    }

    public function testGenerateBaselineWithNoResolvablePathErrors(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--generate-baseline' => true,
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('No baseline path given', $tester->getDisplay());
    }

    public function testMalformedBaselineFileErrorsClearly(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';
        $baselinePath = tempnam(sys_get_temp_dir(), 'spandrel-baseline-');
        self::assertIsString($baselinePath);
        file_put_contents($baselinePath, 'not json');

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--baseline' => $baselinePath,
            ]);

            self::assertSame(Command::INVALID, $exitCode);
            self::assertStringContainsString('Could not parse baseline file', $tester->getDisplay());
        } finally {
            unlink($baselinePath);
        }
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
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-malformed.md',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('could not parse rule bullet', $tester->getDisplay());
    }

    public function testFailsWithMissingRulesetFile(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => '/does/not/exist.md',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testJsonFormatProducesValidJsonWithTheViolation(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--report' => ['json'],
        ]);

        self::assertSame(Command::FAILURE, $exitCode);

        /** @var array{violations: array<int, array<string, mixed>>} $decoded */
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertCount(1, $decoded['violations']);
        self::assertSame('App\Domain\Baz', $decoded['violations'][0]['from']);
    }

    public function testSarifFormatProducesValidSarifWithTheViolation(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--report' => ['sarif'],
        ]);

        self::assertSame(Command::FAILURE, $exitCode);

        /** @var array{version: string, runs: array<int, array{results: array<int, mixed>}>} $decoded */
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame('2.1.0', $decoded['version']);
        self::assertCount(1, $decoded['runs'][0]['results']);
    }

    public function testMermaidFormatRendersLayersAndTheViolatingEdge(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--report' => ['mermaid'],
        ]);

        self::assertSame(Command::FAILURE, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('flowchart LR', $display);
        self::assertStringContainsString('Domain -.->', $display);
        self::assertStringContainsString('violating', $display);
    }

    public function testDiagramScopeViolationsOnlyIsAccepted(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--report' => ['mermaid'],
            '--diagram-scope' => 'violations',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('violating', $tester->getDisplay());
    }

    public function testDiagramLayerRejectsAnUnknownLayerName(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--report' => ['mermaid'],
            '--diagram-layer' => 'Ghost',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Unknown layer "Ghost"', $tester->getDisplay());
    }

    public function testDiagramLayerScopingStillReportsTheViolatingEdge(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--report' => ['mermaid'],
            '--diagram-layer' => 'Domain',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('violating', $tester->getDisplay());
    }

    public function testInvalidDiagramScopeIsRejected(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--report' => ['mermaid'],
            '--diagram-scope' => 'nonsense',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Unsupported diagram scope "nonsense"', $tester->getDisplay());
    }

    public function testMermaidFormatRefusesADiagramOverTheReadabilityLimit(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-many-layers.md',
            '--report' => ['mermaid'],
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('41 layers', $tester->getDisplay());
        self::assertStringContainsString('--diagram-force', $tester->getDisplay());
    }

    public function testDiagramForceRendersDespiteExceedingTheLimit(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-many-layers.md',
            '--report' => ['mermaid'],
            '--diagram-force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Layer40', $tester->getDisplay());
    }

    public function testMultipleReportOptionsProduceBothOutputsInOneRun(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';
        $jsonFile = tempnam(sys_get_temp_dir(), 'spandrel-report-');
        self::assertIsString($jsonFile);

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--report' => ['console', "json:{$jsonFile}"],
            ]);

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('1 violation across 1 file', $tester->getDisplay());

            $written = file_get_contents($jsonFile);
            self::assertIsString($written);
            self::assertStringContainsString('Domain/Baz.php', $written);
        } finally {
            unlink($jsonFile);
        }
    }

    public function testMalformedReportValueIsRejected(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--report' => [':out.txt'],
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Malformed --report value ":out.txt"', $tester->getDisplay());
    }

    public function testUnsupportedFormatInReportIsRejected(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--report' => ['xml'],
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Unsupported format "xml"', $tester->getDisplay());
    }

    public function testReportFailureLeavesNoPartialOutputWritten(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $consoleFile = sys_get_temp_dir().'/spandrel-report-partial-'.uniqid().'.txt';

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture-many-layers.md',
                '--report' => ["console:{$consoleFile}", 'mermaid'],
            ]);

            self::assertSame(Command::INVALID, $exitCode);
            self::assertFileDoesNotExist($consoleFile);
        } finally {
            if (file_exists($consoleFile)) {
                unlink($consoleFile);
            }
        }
    }

    public function testWritesReportToAnOutputFile(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';
        $outputFile = tempnam(sys_get_temp_dir(), 'spandrel-analyse-');
        self::assertIsString($outputFile);

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $fixtures.'/src',
                '--ruleset' => $fixtures.'/architecture.md',
                '--report' => ["console:{$outputFile}"],
            ]);

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertSame('', $tester->getDisplay());

            $written = file_get_contents($outputFile);
            self::assertIsString($written);
            self::assertStringContainsString('1 violation across 1 file', $written);
        } finally {
            unlink($outputFile);
        }
    }

    public function testUnwritableReportTargetIsRejectedWithoutFallingBackToStdout(): void
    {
        $fixtures = __DIR__.'/../Fixtures/ViolatingApp';
        $unwritableTarget = sys_get_temp_dir().'/spandrel-report-missing-dir-'.uniqid().'/out.json';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--report' => ["json:{$unwritableTarget}"],
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        // SymfonyStyle word-wraps the error across lines, so match the path
        // rather than the full sentence verbatim.
        self::assertStringContainsString('Cannot open', $tester->getDisplay());
        self::assertStringContainsString($unwritableTarget, $tester->getDisplay());
        self::assertStringNotContainsString('"violations"', $tester->getDisplay());
        self::assertFileDoesNotExist($unwritableTarget);
    }

    public function testConfigSuppliesPathsAndRuleset(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $configPath = $this->writeTempConfig([$fixtures.'/src'], $fixtures.'/architecture.md');

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute(['--config' => $configPath]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('No violations found', $tester->getDisplay());
        } finally {
            unlink($configPath);
        }
    }

    public function testCliArgumentsOverrideConfig(): void
    {
        $demoApp = __DIR__.'/../Fixtures/DemoApp';
        $violatingApp = __DIR__.'/../Fixtures/ViolatingApp';
        $configPath = $this->writeTempConfig([$demoApp.'/src'], $demoApp.'/architecture.md');

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([
                'paths' => $violatingApp.'/src',
                '--ruleset' => $violatingApp.'/architecture.md',
                '--config' => $configPath,
            ]);

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('1 violation across 1 file', $tester->getDisplay());
        } finally {
            unlink($configPath);
        }
    }

    public function testFailsWhenExplicitConfigFileIsMissing(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--config' => '/does/not/exist.yaml',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Config file not found', $tester->getDisplay());
    }

    public function testMultipleRulesetOptionsMergeIntoOneRun(): void
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
        self::assertStringContainsString('No violations found', $tester->getDisplay());
    }

    public function testDuplicateLayerNameAcrossRulesetFilesFailsClearly(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => [
                $fixtures.'/architecture.md',
                $fixtures.'/architecture.md',
            ],
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('already declared', $tester->getDisplay());
    }

    public function testWithoutStrictABrokenFileIsSkippedSilentlyAndTheRunStillSucceeds(): void
    {
        $fixtures = __DIR__.'/../Fixtures/BrokenApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No violations found', $tester->getDisplay());
    }

    public function testStrictFailsOnAParseErrorAndNamesTheBrokenFile(): void
    {
        $fixtures = __DIR__.'/../Fixtures/BrokenApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--strict' => true,
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Broken.php', $tester->getDisplay());
    }

    public function testStrictFailsOnAnElementNotCoveredByAnyLayer(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture.md',
            '--strict' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('App\Shared\Config is not covered by any layer', $tester->getDisplay());
    }

    public function testStrictSucceedsWhenEverythingParsesAndIsCovered(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-full.md',
            '--strict' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No violations found', $tester->getDisplay());
    }

    public function testMetaStrictElementsFailsWithNoCliFlag(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-meta-strict-elements.md',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('App\Shared\Config is not covered by any layer', $tester->getDisplay());
    }

    public function testMetaStrictElementsAloneDoesNotEnforceParsing(): void
    {
        $fixtures = __DIR__.'/../Fixtures/BrokenApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-meta-strict-elements.md',
        ]);

        // Meta declares only "Any class not in a layer violates rules." — the broken
        // file is still silently skipped, proving the split from --strict is real: this
        // ruleset never opted into failing on a parse error.
        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testMetaStrictParsingFailsWithNoCliFlag(): void
    {
        $fixtures = __DIR__.'/../Fixtures/BrokenApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-meta-strict-parsing.md',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Broken.php', $tester->getDisplay());
    }

    public function testNoStrictOverridesAMetaDeclaredPolicyOff(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            'paths' => $fixtures.'/src',
            '--ruleset' => $fixtures.'/architecture-meta-strict-elements.md',
            '--no-strict' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No violations found', $tester->getDisplay());
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
        $application->addCommand(new AnalyseCommand(
            new ConfigLoader(),
            new RulesetLoader(new RulesetParser()),
            new SourceGraphBuilder(new Loader(), new Parser()),
            new RuleEngine(),
        ));

        return new CommandTester($application->find('analyse'));
    }
}
