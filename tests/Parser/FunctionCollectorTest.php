<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;
use Spandrel\Spandrel\Parser\Parser;

final class FunctionCollectorTest extends TestCase
{
    public function testCollectsNamespacedFunctionDeclaration(): void
    {
        $functions = $this->functions(<<<'PHP'
            <?php

            namespace App;

            function helper(): void
            {
            }
            PHP);

        self::assertEquals(
            [new Element('App\helper', ElementKind::Function, 'test.php', 5, 1)],
            $functions,
        );
    }

    public function testFileWithOnlyClassesYieldsNoFunctionElements(): void
    {
        $functions = $this->functions(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            PHP);

        self::assertSame([], $functions);
    }

    public function testNestedFunctionDeclarationIsCollected(): void
    {
        $functions = $this->functions(<<<'PHP'
            <?php

            namespace App;

            function outer(): void
            {
                function inner(): void
                {
                }
            }
            PHP);

        self::assertEquals(
            [
                new Element('App\outer', ElementKind::Function, 'test.php', 5, 1),
                new Element('App\inner', ElementKind::Function, 'test.php', 7, 5),
            ],
            $functions,
        );
    }

    /**
     * @return Element[]
     */
    private function functions(string $code): array
    {
        $elements = (new Parser())->parse('test.php', $code)->elements;

        return array_values(array_filter(
            $elements,
            static fn (Element $e): bool => $e->kind === ElementKind::Function,
        ));
    }
}
