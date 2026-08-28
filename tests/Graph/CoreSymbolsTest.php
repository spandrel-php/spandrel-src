<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\CoreSymbols;

final class CoreSymbolsTest extends TestCase
{
    public function testRecognisesRealBuiltinFunctions(): void
    {
        self::assertTrue(CoreSymbols::isCoreFunction('strlen'));
        self::assertTrue(CoreSymbols::isCoreFunction('array_map'));
    }

    public function testDoesNotRecogniseUserlandOrFictionalFunctions(): void
    {
        self::assertFalse(CoreSymbols::isCoreFunction('App\Util\helper'));
        self::assertFalse(CoreSymbols::isCoreFunction('Spandrel\Spandrel\Graph\CoreSymbols::isCoreFunction'));
        self::assertFalse(CoreSymbols::isCoreFunction('this_function_does_not_exist_anywhere'));
    }

    public function testRecognisesRealBuiltinClasses(): void
    {
        self::assertTrue(CoreSymbols::isCoreClass('RuntimeException'));
        self::assertTrue(CoreSymbols::isCoreClass('ArrayObject'));
    }

    public function testDoesNotRecogniseSpandrelsOwnClassesEvenThoughTheyAreLoaded(): void
    {
        // A real class, loaded in this very process via Composer autoload — must not be
        // misclassified as "core" just because class_exists() is true for it.
        self::assertFalse(CoreSymbols::isCoreClass(CoreSymbols::class));
        self::assertFalse(CoreSymbols::isCoreClass('App\Util\Widget'));
    }
}
