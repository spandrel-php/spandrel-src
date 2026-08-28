<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Parser\ColumnCalculator;

final class ColumnCalculatorTest extends TestCase
{
    public function testFirstCharacterIsColumnOne(): void
    {
        $calculator = new ColumnCalculator('hello world');

        self::assertSame(1, $calculator->columnAt(0));
    }

    public function testMidLineOffset(): void
    {
        $calculator = new ColumnCalculator('hello world');

        self::assertSame(7, $calculator->columnAt(6));
    }

    public function testColumnResetsAfterANewline(): void
    {
        $calculator = new ColumnCalculator("line1\nline2");

        self::assertSame(1, $calculator->columnAt(6));
        self::assertSame(3, $calculator->columnAt(8));
    }

    public function testThirdLineAccountsForBothPrecedingNewlines(): void
    {
        $calculator = new ColumnCalculator("a\nbb\nccc");

        // 'ccc' starts at offset 5 (a=0, \n=1, bb=2-3, \n=4, c=5).
        self::assertSame(1, $calculator->columnAt(5));
        self::assertSame(3, $calculator->columnAt(7));
    }

    public function testNegativeFilePosFallsBackToColumnOne(): void
    {
        $calculator = new ColumnCalculator('hello world');

        self::assertSame(1, $calculator->columnAt(-1));
    }
}
