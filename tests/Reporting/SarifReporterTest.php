<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Reporting;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Reporting\SarifReporter;
use Spandrel\Spandrel\RuleEngine\Violation;
use Spandrel\Spandrel\Version\Version;

/**
 * @phpstan-type SarifDocument array{
 *     version: string,
 *     runs: array<int, array{
 *         tool: array{driver: array{name: string, version: string, rules: array<int, array{id: string, shortDescription: array{text: string}}>}},
 *         results: array<int, array{
 *             ruleId: string,
 *             level: string,
 *             message: array{text: string},
 *             locations: array<int, array{physicalLocation: array{artifactLocation: array{uri: string}, region: array{startLine: int, startColumn?: int, endLine?: int, endColumn?: int}}}>,
 *         }>,
 *     }>,
 * }
 */
final class SarifReporterTest extends TestCase
{
    public function testTopLevelShape(): void
    {
        $output = (new SarifReporter())->format([]);
        $decoded = $this->decode($output);

        self::assertSame('2.1.0', $decoded['version']);
        self::assertSame('Spandrel', $decoded['runs'][0]['tool']['driver']['name']);
        self::assertSame(Version::current(), $decoded['runs'][0]['tool']['driver']['version']);
        self::assertSame([], $decoded['runs'][0]['tool']['driver']['rules']);
        self::assertSame([], $decoded['runs'][0]['results']);
    }

    public function testResultReferencesAMatchingCatalogRule(): void
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

        $output = (new SarifReporter())->format([$violation]);
        $decoded = $this->decode($output);

        $rules = $decoded['runs'][0]['tool']['driver']['rules'];
        $results = $decoded['runs'][0]['results'];

        self::assertCount(1, $rules);
        self::assertCount(1, $results);
        self::assertSame($rules[0]['id'], $results[0]['ruleId']);
        self::assertSame('`Domain` must not depend on `Infrastructure`', $rules[0]['shortDescription']['text']);
        self::assertSame('error', $results[0]['level']);
        self::assertSame('message text', $results[0]['message']['text']);
        self::assertSame('src/Domain/Baz.php', $results[0]['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        self::assertSame(9, $results[0]['locations'][0]['physicalLocation']['region']['startLine']);
        self::assertArrayNotHasKey('startColumn', $results[0]['locations'][0]['physicalLocation']['region']);
    }

    public function testRegionIncludesColumnAndEndPositionWhenTheViolationCarriesThem(): void
    {
        $violation = new Violation(
            rule: '`Domain` must not depend on `Infrastructure`',
            fromElement: 'App\Domain\Baz',
            toElement: 'App\Infrastructure\Bar',
            dependencyKind: DependencyKind::Extends,
            file: 'src/Domain/Baz.php',
            line: 9,
            message: 'message text',
            column: 21,
            endLine: 9,
            endColumn: 44,
        );

        $output = (new SarifReporter())->format([$violation]);
        $decoded = $this->decode($output);

        /** @var array{startLine: int, startColumn: int, endLine: int, endColumn: int} $region */
        $region = $decoded['runs'][0]['results'][0]['locations'][0]['physicalLocation']['region'];

        self::assertSame(9, $region['startLine']);
        self::assertSame(21, $region['startColumn']);
        self::assertSame(9, $region['endLine']);
        self::assertSame(44, $region['endColumn']);
    }

    public function testDistinctRuleTextsProduceDistinctCatalogEntries(): void
    {
        $a = new Violation('rule A', 'A', 'B', DependencyKind::Extends, 'A.php', 1, 'a');
        $b = new Violation('rule B', 'C', 'D', DependencyKind::Extends, 'C.php', 1, 'b');
        $aAgain = new Violation('rule A', 'E', 'F', DependencyKind::Extends, 'E.php', 1, 'c');

        $output = (new SarifReporter())->format([$a, $b, $aAgain]);
        $decoded = $this->decode($output);

        self::assertCount(2, $decoded['runs'][0]['tool']['driver']['rules']);
        self::assertCount(3, $decoded['runs'][0]['results']);
        self::assertSame(
            $decoded['runs'][0]['results'][0]['ruleId'],
            $decoded['runs'][0]['results'][2]['ruleId'],
        );
    }

    /**
     * @return SarifDocument
     */
    private function decode(string $json): array
    {
        /** @var SarifDocument $decoded */
        $decoded = json_decode($json, true);

        return $decoded;
    }
}
