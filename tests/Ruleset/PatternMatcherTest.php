<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Ruleset;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Ruleset\PatternMatcher;

final class PatternMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function patterns(): iterable
    {
        yield 'exact match, no wildcards' => ['App\Factory\ConstraintFactory', 'App\Factory\ConstraintFactory', true];
        yield 'exact match, no wildcards, mismatch' => ['App\Factory\ConstraintFactory', 'App\Factory\Other', false];
        yield 'trailing ** matches the namespace itself' => ['App\Domain\**', 'App\Domain', true];
        yield 'trailing ** matches nested' => ['App\Domain\**', 'App\Domain\Model\User', true];
        yield 'trailing ** does not match sibling' => ['App\Domain\**', 'App\Infrastructure\User', false];
        yield 'single * matches exactly one segment' => ['App\*\Command', 'App\Foo\Command', true];
        yield 'single * does not match two segments' => ['App\*\Command', 'App\Foo\Bar\Command', false];
        yield 'single * does not match zero segments' => ['App\*\Command', 'App\Command', false];
        yield 'cross-cutting pattern with trailing **' => ['App\*\Command\**', 'App\Foo\Command\CreateUser', true];
        yield 'leading backslash is ignored' => ['\App\Domain\**', 'App\Domain\User', true];
    }

    #[DataProvider('patterns')]
    public function testMatches(string $pattern, string $fqcn, bool $expected): void
    {
        self::assertSame($expected, PatternMatcher::matches($pattern, $fqcn));
    }
}
