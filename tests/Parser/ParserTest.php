<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\ElementKind;
use Spandrel\Spandrel\Parser\Parser;

final class ParserTest extends TestCase
{
    public function testCollectsClasslikeDeclarationsWithResolvedNamespaces(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App\Domain;

            class User
            {
            }

            interface UserRepositoryInterface
            {
            }

            trait Timestamps
            {
            }

            enum Status
            {
                case Active;
            }

            function helper(): void
            {
            }

            $anonymous = new class {
            };
            PHP;

        $elements = (new Parser())->parse('User.php', $code)->elements;

        self::assertCount(5, $elements);

        $byFqcn = [];
        foreach ($elements as $element) {
            $byFqcn[$element->fqcn] = $element;
        }

        self::assertSame(ElementKind::ClassLike, $byFqcn['App\Domain\User']->kind);
        self::assertSame(ElementKind::Interface, $byFqcn['App\Domain\UserRepositoryInterface']->kind);
        self::assertSame(ElementKind::Trait, $byFqcn['App\Domain\Timestamps']->kind);
        self::assertSame(ElementKind::Enum, $byFqcn['App\Domain\Status']->kind);
        self::assertSame(ElementKind::Function, $byFqcn['App\Domain\helper']->kind);

        self::assertSame('User.php', $byFqcn['App\Domain\User']->file);
        self::assertSame(5, $byFqcn['App\Domain\User']->line);
    }

    public function testFileWithNoDeclarationsYieldsNoElements(): void
    {
        $elements = (new Parser())->parse('empty.php', "<?php\n\necho 'hello';\n")->elements;

        self::assertSame([], $elements);
    }
}
