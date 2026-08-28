<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Reporting;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Reporting\ConsoleReporter;
use Spandrel\Spandrel\RuleEngine\Violation;

final class ConsoleReporterTest extends TestCase
{
    public function testNoViolationsProducesASuccessLine(): void
    {
        $output = (new ConsoleReporter())->format([]);

        self::assertStringContainsString('No violations found.', $output);
    }

    public function testGroupsByFileAndSummarizes(): void
    {
        $violation = new Violation(
            rule: '`Domain` must not depend on `Infrastructure`',
            fromElement: 'App\Domain\Baz',
            toElement: 'App\Infrastructure\Bar',
            dependencyKind: DependencyKind::Extends,
            file: 'src/Domain/Baz.php',
            line: 9,
            message: 'App\Domain\Baz (Domain) must not depend on App\Infrastructure\Bar (Infrastructure) via extends',
        );

        $output = (new ConsoleReporter())->format([$violation]);

        self::assertStringContainsString('src/Domain/Baz.php', $output);
        self::assertStringContainsString('Line 9:', $output);
        self::assertStringContainsString(
            'App\Domain\Baz (Domain) must not depend on App\Infrastructure\Bar (Infrastructure) via extends',
            $output,
        );
        self::assertStringContainsString('1 violation across 1 file', $output);
        self::assertStringNotContainsString('Rule:', $output);
    }

    public function testNoNewViolationsWithSomeBaselinedProducesAnAdjustedSuccessLine(): void
    {
        $output = (new ConsoleReporter(baselinedCount: 3))->format([]);

        self::assertStringContainsString('No new violations found (3 baselined).', $output);
    }

    public function testSummaryLineIncludesBaselinedCountWhenSomeViolationsRemain(): void
    {
        $violation = new Violation(
            rule: '`Domain` must not depend on `Infrastructure`',
            fromElement: 'App\Domain\Baz',
            toElement: 'App\Infrastructure\Bar',
            dependencyKind: DependencyKind::Extends,
            file: 'src/Domain/Baz.php',
            line: 9,
            message: 'message',
        );

        $output = (new ConsoleReporter(baselinedCount: 2))->format([$violation]);

        self::assertStringContainsString('1 violation across 1 file', $output);
        self::assertStringContainsString('(2 baselined)', $output);
    }

    public function testVerboseIncludesRuleText(): void
    {
        $violation = new Violation(
            rule: '`Domain` must not depend on `Infrastructure`',
            fromElement: 'App\Domain\Baz',
            toElement: 'App\Infrastructure\Bar',
            dependencyKind: DependencyKind::Extends,
            file: 'src/Domain/Baz.php',
            line: 9,
            message: 'message',
        );

        $output = (new ConsoleReporter(true))->format([$violation]);

        self::assertStringContainsString('Rule: `Domain` must not depend on `Infrastructure`', $output);
    }
}
