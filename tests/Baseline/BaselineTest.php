<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Baseline;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Baseline\Baseline;
use Spandrel\Spandrel\Baseline\BaselineParseException;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\RuleEngine\Violation;

final class BaselineTest extends TestCase
{
    public function testFromViolationsThenContainsRoundTrips(): void
    {
        $violation = $this->violation('App\Domain\Baz', 'App\Infrastructure\Bar', DependencyKind::Extends, 9);
        $baseline = Baseline::fromViolations([$violation]);

        self::assertTrue($baseline->contains($violation));
        self::assertSame(1, $baseline->count());
    }

    public function testMatchesByElementPairAndKindIgnoringFileAndLine(): void
    {
        $original = $this->violation('App\Domain\Baz', 'App\Infrastructure\Bar', DependencyKind::Extends, 9);
        $baseline = Baseline::fromViolations([$original]);

        $movedElsewhere = $this->violation('App\Domain\Baz', 'App\Infrastructure\Bar', DependencyKind::Extends, 42);

        self::assertTrue($baseline->contains($movedElsewhere));
    }

    public function testDoesNotMatchADifferentDependencyKind(): void
    {
        $baseline = Baseline::fromViolations([
            $this->violation('App\Domain\Baz', 'App\Infrastructure\Bar', DependencyKind::Extends, 9),
        ]);

        $differentKind = $this->violation('App\Domain\Baz', 'App\Infrastructure\Bar', DependencyKind::Implements, 9);

        self::assertFalse($baseline->contains($differentKind));
    }

    public function testMissingFileIsAnEmptyBaselineNotAnError(): void
    {
        $baseline = Baseline::load('/does/not/exist/spandrel-baseline.json');

        self::assertSame(0, $baseline->count());
    }

    public function testMalformedJsonThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'spandrel-baseline-');
        self::assertIsString($path);
        file_put_contents($path, 'not json');

        try {
            $this->expectException(BaselineParseException::class);
            Baseline::load($path);
        } finally {
            unlink($path);
        }
    }

    public function testWrongShapeThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'spandrel-baseline-');
        self::assertIsString($path);
        file_put_contents($path, json_encode(['violations' => [['from' => 'A']]]));

        try {
            $this->expectException(BaselineParseException::class);
            Baseline::load($path);
        } finally {
            unlink($path);
        }
    }

    public function testWriteThenLoadRoundTrips(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'spandrel-baseline-');
        self::assertIsString($path);

        try {
            $violation = $this->violation('App\Domain\Baz', 'App\Infrastructure\Bar', DependencyKind::Extends, 9);
            Baseline::fromViolations([$violation])->write($path);

            $loaded = Baseline::load($path);

            self::assertTrue($loaded->contains($violation));
            self::assertSame(1, $loaded->count());
        } finally {
            unlink($path);
        }
    }

    private function violation(string $from, string $to, DependencyKind $kind, int $line): Violation
    {
        return new Violation(
            rule: 'rule text',
            fromElement: $from,
            toElement: $to,
            dependencyKind: $kind,
            file: 'src/Domain/Baz.php',
            line: $line,
            message: 'message',
        );
    }
}
