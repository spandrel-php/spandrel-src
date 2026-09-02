<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Reporting;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Reporting\GithubReporter;
use Spandrel\Spandrel\RuleEngine\Violation;

final class GithubReporterTest extends TestCase
{
    public function testNoViolationsProducesNoOutput(): void
    {
        self::assertSame('', (new GithubReporter())->format([]));
    }

    public function testOneWorkflowCommandPerViolation(): void
    {
        $output = (new GithubReporter())->format([
            $this->violation(file: 'Domain/Foo.php', line: 4),
            $this->violation(file: 'Domain/Baz.php', line: 9),
        ]);

        self::assertSame(
            "::error file=Domain/Foo.php,line=4,title=Spandrel%3A `Domain` must not depend on `Infrastructure`::message text\n"
            ."::error file=Domain/Baz.php,line=9,title=Spandrel%3A `Domain` must not depend on `Infrastructure`::message text\n",
            $output,
        );
    }

    public function testOptionalPositionFieldsAreOmittedWhenAbsent(): void
    {
        $output = (new GithubReporter())->format([
            $this->violation(column: 13, endLine: 6, endColumn: 20),
        ]);

        self::assertStringContainsString('file=Domain/Foo.php,line=4,col=13,endLine=6,endColumn=20,title=', $output);
    }

    /**
     * `,` and `:` separate a workflow command's properties from each other
     * and from its message, so a rule text containing either has to be
     * percent-escaped or the annotation parses as garbage.
     */
    public function testPropertyValuesEscapeTheCommandSyntax(): void
    {
        $output = (new GithubReporter())->format([
            $this->violation(rule: '`Domain` may only depend on `Shared`, `Graph`: no others'),
        ]);

        self::assertStringContainsString(
            'title=Spandrel%3A `Domain` may only depend on `Shared`%2C `Graph`%3A no others::',
            $output,
        );
    }

    public function testMessageEscapesNewlinesAndPercent(): void
    {
        $output = (new GithubReporter())->format([
            $this->violation(message: "100% broken\nsecond line"),
        ]);

        self::assertStringEndsWith('::100%25 broken%0Asecond line'."\n", $output);
    }

    /**
     * GitHub resolves `file=` against the repository root, so the source
     * path the violation was found under has to be put back in front of it.
     */
    public function testFileIsPrefixedWithTheSourcePathThatHoldsIt(): void
    {
        $reporter = new GithubReporter([
            __DIR__.'/../Fixtures/DemoApp/src',
            __DIR__.'/../Fixtures/ViolatingApp/src',
        ]);

        $output = $reporter->format([$this->violation(file: 'Domain/Baz.php')]);

        self::assertStringContainsString('file=tests/Fixtures/ViolatingApp/src/Domain/Baz.php,', $output);
    }

    public function testSingleFileSourcePathResolvesFromItsBasename(): void
    {
        $reporter = new GithubReporter([__DIR__.'/../Fixtures/ViolatingApp/src/Domain/Foo.php']);

        $output = $reporter->format([$this->violation(file: 'Foo.php')]);

        self::assertStringContainsString('file=tests/Fixtures/ViolatingApp/src/Domain/Foo.php,', $output);
    }

    public function testUnresolvableFileIsReportedAsIs(): void
    {
        $reporter = new GithubReporter([__DIR__.'/../Fixtures/DemoApp/src']);

        $output = $reporter->format([$this->violation(file: 'Nowhere/Missing.php')]);

        self::assertStringContainsString('file=Nowhere/Missing.php,', $output);
    }

    private function violation(
        string $rule = '`Domain` must not depend on `Infrastructure`',
        string $file = 'Domain/Foo.php',
        int $line = 4,
        string $message = 'message text',
        ?int $column = null,
        ?int $endLine = null,
        ?int $endColumn = null,
    ): Violation {
        return new Violation(
            rule: $rule,
            fromElement: 'App\Domain\Foo',
            toElement: 'App\Infrastructure\Db',
            dependencyKind: DependencyKind::ParamType,
            file: $file,
            line: $line,
            message: $message,
            column: $column,
            endLine: $endLine,
            endColumn: $endColumn,
        );
    }
}
