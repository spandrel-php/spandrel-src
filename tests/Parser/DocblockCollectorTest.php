<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Parser\Parser;

final class DocblockCollectorTest extends TestCase
{
    public function testParamArrayOfClassIsResolved(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Bar
            {
                /**
                 * @param Foo[] $items
                 */
                public function m($items): void
                {
                }
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\Bar', 'App\Foo', DependencyKind::ParamType, 'test.php', 11, 5, 13, 5)],
            $dependencies,
        );
    }

    public function testReturnGenericIncludesBothTheContainerAndItsArgument(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Collection {}
            class Foo {}
            class Bar
            {
                /**
                 * @return Collection<Foo>
                 */
                public function m()
                {
                }
            }
            PHP);

        $byTo = [];
        foreach ($dependencies as $dependency) {
            $byTo[$dependency->to] = $dependency;
        }

        self::assertCount(2, $dependencies);
        self::assertArrayHasKey('App\Collection', $byTo);
        self::assertArrayHasKey('App\Foo', $byTo);
        self::assertSame(DependencyKind::ReturnType, $byTo['App\Collection']->kind);
        self::assertSame(DependencyKind::ReturnType, $byTo['App\Foo']->kind);
    }

    public function testVarOnPropertyIsResolved(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Bar
            {
                /**
                 * @var Foo
                 */
                public $x;
            }
            PHP);

        self::assertCount(1, $dependencies);
        self::assertSame('App\Bar', $dependencies[0]->from);
        self::assertSame('App\Foo', $dependencies[0]->to);
        self::assertSame(DependencyKind::PropertyType, $dependencies[0]->kind);
    }

    public function testAliasedNameIsResolved(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            use App\Other\Baz as Aliased;

            class Bar
            {
                /**
                 * @param Aliased $x
                 */
                public function m($x): void
                {
                }
            }
            PHP);

        self::assertCount(1, $dependencies);
        self::assertSame('App\Other\Baz', $dependencies[0]->to);
    }

    public function testFullyQualifiedNameIsResolvedAsIs(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Bar
            {
                /**
                 * @param \Fully\Qualified\Name $x
                 */
                public function m($x): void
                {
                }
            }
            PHP);

        self::assertCount(1, $dependencies);
        self::assertSame('Fully\Qualified\Name', $dependencies[0]->to);
    }

    public function testRelativeNameIsResolvedAgainstCurrentNamespace(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Bar
            {
                /**
                 * @param Sub\Name $x
                 */
                public function m($x): void
                {
                }
            }
            PHP);

        self::assertCount(1, $dependencies);
        self::assertSame('App\Sub\Name', $dependencies[0]->to);
    }

    public function testBuiltinAndScalarTypesProduceNoEdges(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Bar
            {
                /**
                 * @param int $a
                 * @param array $b
                 * @param class-string $c
                 * @return void
                 */
                public function m($a, $b, $c)
                {
                }
            }
            PHP);

        self::assertSame([], $dependencies);
    }

    public function testSelfParentStaticAndThisProduceNoEdges(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Bar
            {
                /**
                 * @param self $a
                 * @param parent $b
                 * @param static $c
                 * @return $this
                 */
                public function m($a, $b, $c)
                {
                }
            }
            PHP);

        self::assertSame([], $dependencies);
    }

    public function testUnionTypeProducesOneEdgePerMember(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Baz {}
            class Bar
            {
                /**
                 * @param Foo|Baz $x
                 */
                public function m($x): void
                {
                }
            }
            PHP);

        $targets = array_map(static fn (Dependency $d): string => $d->to, $dependencies);
        sort($targets);

        self::assertSame(['App\Baz', 'App\Foo'], $targets);
    }

    public function testNoDocblockProducesNoEdges(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Bar
            {
                public function m($x): void
                {
                }
            }
            PHP);

        self::assertSame([], $dependencies);
    }

    public function testMalformedDocblockIsSkippedWithoutCrashing(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Bar
            {
                /**
                 * @param $this is not a valid type expression here <<<
                 */
                public function m($x): void
                {
                }
            }
            PHP);

        self::assertSame([], $dependencies);
    }

    public function testFreeFunctionDocblockProducesNoEdges(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}

            /**
             * @param Foo $x
             */
            function helper($x): void
            {
            }
            PHP);

        self::assertSame([], $dependencies);
    }

    public function testAnonymousClassDocblockProducesNoEdges(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}

            $x = new class {
                /**
                 * @param Foo $a
                 */
                public function m($a): void
                {
                }
            };
            PHP);

        self::assertSame([], $dependencies);
    }

    /**
     * @return Dependency[]
     */
    private function parse(string $code): array
    {
        return (new Parser())->parse('test.php', $code)->dependencies;
    }
}
