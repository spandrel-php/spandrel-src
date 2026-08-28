<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Reporting;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Reporting\JsonReporter;
use Spandrel\Spandrel\RuleEngine\Violation;

final class JsonReporterTest extends TestCase
{
    public function testEmptyViolationsProducesAnEmptyArray(): void
    {
        $output = (new JsonReporter())->format([]);

        self::assertSame(['violations' => []], json_decode($output, true));
    }

    public function testOneEntryPerViolation(): void
    {
        $violation = new Violation(
            rule: '`Domain` must not depend on `Infrastructure`',
            fromElement: 'App\Domain\Baz',
            toElement: 'App\Infrastructure\Bar',
            dependencyKind: DependencyKind::Extends,
            file: 'src/Domain/Baz.php',
            line: 9,
            message: 'message text',
        );

        $output = (new JsonReporter())->format([$violation]);

        /** @var array{violations: array<int, array<string, mixed>>} $decoded */
        $decoded = json_decode($output, true);

        self::assertCount(1, $decoded['violations']);
        self::assertSame([
            'rule' => '`Domain` must not depend on `Infrastructure`',
            'from' => 'App\Domain\Baz',
            'to' => 'App\Infrastructure\Bar',
            'kind' => 'extends',
            'file' => 'src/Domain/Baz.php',
            'line' => 9,
            'message' => 'message text',
        ], $decoded['violations'][0]);
    }
}
