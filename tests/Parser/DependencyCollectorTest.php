<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Parser\Parser;

final class DependencyCollectorTest extends TestCase
{
    public function testClassExtends(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Base {}
            class Child extends Base {}
            PHP);

        self::assertEquals(
            [new Dependency('App\Child', 'App\Base', DependencyKind::Extends, 'test.php', 6, 1, 6, 27)],
            $dependencies,
        );
    }

    public function testClassImplementsMultipleInterfaces(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            interface A {}
            interface B {}
            class C implements A, B {}
            PHP);

        self::assertEquals(
            [
                new Dependency('App\C', 'App\A', DependencyKind::Implements, 'test.php', 7, 1, 7, 26),
                new Dependency('App\C', 'App\B', DependencyKind::Implements, 'test.php', 7, 1, 7, 26),
            ],
            $dependencies,
        );
    }

    public function testInterfaceExtendsMultipleInterfaces(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            interface A {}
            interface B {}
            interface C extends A, B {}
            PHP);

        self::assertEquals(
            [
                new Dependency('App\C', 'App\A', DependencyKind::Extends, 'test.php', 7, 1, 7, 27),
                new Dependency('App\C', 'App\B', DependencyKind::Extends, 'test.php', 7, 1, 7, 27),
            ],
            $dependencies,
        );
    }

    public function testUseTrait(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            trait T {}
            class C {
                use T;
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\C', 'App\T', DependencyKind::UseTrait, 'test.php', 7, 5, 7, 10)],
            $dependencies,
        );
    }

    public function testParamTypeIncludingNullableAndUnion(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Bar {}
            class Baz {}
            class Qux
            {
                public function m(?Foo $a, Bar|Baz $b): void
                {
                }
            }
            PHP);

        self::assertEquals(
            [
                new Dependency('App\Qux', 'App\Foo', DependencyKind::ParamType, 'test.php', 10, 23, 10, 29),
                new Dependency('App\Qux', 'App\Bar', DependencyKind::ParamType, 'test.php', 10, 32, 10, 41),
                new Dependency('App\Qux', 'App\Baz', DependencyKind::ParamType, 'test.php', 10, 32, 10, 41),
            ],
            $dependencies,
        );
    }

    public function testPropertyType(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Bar
            {
                public Foo $x;
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\Bar', 'App\Foo', DependencyKind::PropertyType, 'test.php', 8, 5, 8, 18)],
            $dependencies,
        );
    }

    public function testReturnTypeSkipsNullInUnion(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Bar
            {
                public function m(): Foo|null
                {
                    return null;
                }
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\Bar', 'App\Foo', DependencyKind::ReturnType, 'test.php', 8, 5, 11, 5)],
            $dependencies,
        );
    }

    public function testReturnTypeLocationSpansTheWholeMethodSignatureAcrossLines(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Bar
            {
                public function m(
                    int $a,
                ): Foo
                {
                    return new Foo();
                }
            }
            PHP);

        $returnType = array_values(array_filter(
            $dependencies,
            static fn (Dependency $d): bool => $d->kind === DependencyKind::ReturnType,
        ));

        self::assertCount(1, $returnType);
        self::assertSame(8, $returnType[0]->line);
        self::assertSame(13, $returnType[0]->endLine);
        self::assertNotSame($returnType[0]->line, $returnType[0]->endLine);
    }

    public function testInstantiate(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Bar
            {
                public function m(): void
                {
                    new Foo();
                }
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\Bar', 'App\Foo', DependencyKind::Instantiate, 'test.php', 10, 9, 10, 17)],
            $dependencies,
        );
    }

    public function testStaticCall(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo
            {
                public static function make(): void
                {
                }
            }
            class Bar
            {
                public function m(): void
                {
                    Foo::make();
                }
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\Bar', 'App\Foo', DependencyKind::StaticCall, 'test.php', 15, 9, 15, 19)],
            $dependencies,
        );
    }

    public function testInstanceof(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Bar
            {
                public function m(mixed $x): void
                {
                    if ($x instanceof Foo) {
                    }
                }
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\Bar', 'App\Foo', DependencyKind::Instanceof, 'test.php', 10, 13, 10, 29)],
            $dependencies,
        );
    }

    public function testCatch(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class FooException extends \Exception {}
            class Bar
            {
                public function m(): void
                {
                    try {
                    } catch (FooException $e) {
                    }
                }
            }
            PHP);

        $catchDependencies = array_values(array_filter(
            $dependencies,
            static fn (Dependency $d): bool => $d->kind === DependencyKind::Catch,
        ));

        self::assertEquals(
            [new Dependency('App\Bar', 'App\FooException', DependencyKind::Catch, 'test.php', 11, 11, 12, 9)],
            $catchDependencies,
        );
    }

    public function testCallToFullyQualifiedFunction(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo
            {
                public function m(): void
                {
                    \strlen('x');
                }
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\Foo', 'strlen', DependencyKind::Call, 'test.php', 9, 9, 9, 20)],
            $dependencies,
        );
    }

    public function testCallToQualifiedNamespacedFunction(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo
            {
                public function m(): void
                {
                    Sub\helper();
                }
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\Foo', 'App\Sub\helper', DependencyKind::Call, 'test.php', 9, 9, 9, 20)],
            $dependencies,
        );
    }

    public function testUnqualifiedCallToAnUnknownFunctionProducesAnUnresolvableCallEdge(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo
            {
                public function m(): void
                {
                    helper();
                }
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\Foo', Dependency::UNRESOLVABLE, DependencyKind::Call, 'test.php', 9, 9, 9, 16)],
            $dependencies,
        );
    }

    public function testUnqualifiedCallToARealCoreFunctionResolvesByName(): void
    {
        // A bare call inside a namespace is ambiguous in general (PHP falls back to the
        // global function only if no namespaced one exists) — but when the bare name is a
        // real PHP core function, that's true in the overwhelming majority of real code, so
        // it's resolved as the core function rather than left unresolvable.
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo
            {
                public function m(): void
                {
                    array_map('strtoupper', []);
                }
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\Foo', 'array_map', DependencyKind::Call, 'test.php', 9, 9, 9, 35)],
            $dependencies,
        );
    }

    public function testDynamicCallProducesAnUnresolvableCallEdge(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo
            {
                public function m(): void
                {
                    $fn = 'strlen';
                    $fn('x');
                }
            }
            PHP);

        self::assertEquals(
            [new Dependency('App\Foo', Dependency::UNRESOLVABLE, DependencyKind::Call, 'test.php', 10, 9, 10, 16)],
            $dependencies,
        );
    }

    public function testThrowNewAlsoProducesAnInstantiateEdge(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class FooException extends \Exception {}
            class Bar
            {
                public function m(): void
                {
                    throw new FooException();
                }
            }
            PHP);

        $throwOrInstantiate = array_values(array_filter(
            $dependencies,
            static fn (Dependency $d): bool => $d->from === 'App\Bar',
        ));

        // Instantiate is appended first: it's added when the nested `new` expression
        // itself is visited, which (bottom-up) happens before the enclosing Throw_'s
        // own leaveNode — see the comment on the Throw_ handling in DependencyCollector.
        self::assertEquals(
            [
                new Dependency('App\Bar', 'App\FooException', DependencyKind::Instantiate, 'test.php', 10, 15, 10, 32),
                new Dependency('App\Bar', 'App\FooException', DependencyKind::Throw, 'test.php', 10, 9, 10, 32),
            ],
            $throwOrInstantiate,
        );
    }

    public function testRethrowProducesAnUnresolvableThrowEdge(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Bar
            {
                public function m(): void
                {
                    try {
                    } catch (\Exception $e) {
                        throw $e;
                    }
                }
            }
            PHP);

        $throwDependencies = array_values(array_filter(
            $dependencies,
            static fn (Dependency $d): bool => $d->kind === DependencyKind::Throw,
        ));

        self::assertEquals(
            [new Dependency('App\Bar', Dependency::UNRESOLVABLE, DependencyKind::Throw, 'test.php', 11, 13, 11, 20)],
            $throwDependencies,
        );
    }

    public function testThrowOfANonNewExpressionProducesAnUnresolvableThrowEdge(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Bar
            {
                public function m(): void
                {
                    throw $this->factory->make();
                }
            }
            PHP);

        $throwDependencies = array_values(array_filter(
            $dependencies,
            static fn (Dependency $d): bool => $d->kind === DependencyKind::Throw,
        ));

        self::assertEquals(
            [new Dependency('App\Bar', Dependency::UNRESOLVABLE, DependencyKind::Throw, 'test.php', 9, 9, 9, 36)],
            $throwDependencies,
        );
    }

    public function testThrowOfADynamicClassProducesAnUnresolvableThrowEdge(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Bar
            {
                public function m(string $exceptionClass): void
                {
                    throw new $exceptionClass();
                }
            }
            PHP);

        $throwDependencies = array_values(array_filter(
            $dependencies,
            static fn (Dependency $d): bool => $d->kind === DependencyKind::Throw,
        ));

        self::assertEquals(
            [new Dependency('App\Bar', Dependency::UNRESOLVABLE, DependencyKind::Throw, 'test.php', 9, 9, 9, 35)],
            $throwDependencies,
        );
    }

    public function testSkipsSelfParentAndStatic(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Base
            {
                public static function make(): self
                {
                    return new self();
                }
            }
            class Child extends Base
            {
                public function m(): void
                {
                    parent::make();
                    $x = new static();
                }
            }
            PHP);

        $suppressibleKinds = [DependencyKind::ReturnType, DependencyKind::Instantiate, DependencyKind::StaticCall];
        $suppressible = array_values(array_filter(
            $dependencies,
            static fn (Dependency $d): bool => in_array($d->kind, $suppressibleKinds, true),
        ));

        self::assertSame([], $suppressible);
        self::assertEquals(
            [new Dependency('App\Child', 'App\Base', DependencyKind::Extends, 'test.php', 12, 1, 19, 1)],
            $dependencies,
        );
    }

    public function testSkipsScalarAndBuiltinTypeHints(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo
            {
                public array $list;

                public function m(int $a, string $b): bool
                {
                    return true;
                }
            }
            PHP);

        self::assertSame([], $dependencies);
    }

    public function testAnonymousClassProducesNoDependenciesInOrOutOfItsBody(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Bar
            {
                public function m(): void
                {
                    $x = new class extends Foo {
                        public function inner(Foo $f): void
                        {
                        }
                    };
                }
            }
            PHP);

        self::assertSame([], $dependencies);
    }

    public function testClassLevelAttributeIsCollected(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Route {}

            #[Route]
            class Controller {}
            PHP);

        self::assertEquals(
            [new Dependency('App\Controller', 'App\Route', DependencyKind::Attribute, 'test.php', 7, 3, 7, 7)],
            $dependencies,
        );
    }

    public function testMethodPropertyAndParamLevelAttributesAreCollected(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Route {}
            class Inject {}
            class Validate {}

            class Controller
            {
                #[Route]
                public $handler;

                #[Inject]
                public function m(#[Validate] $x): void
                {
                }
            }
            PHP);

        $targets = array_map(
            static fn (Dependency $d): string => $d->to,
            array_values(array_filter($dependencies, static fn (Dependency $d): bool => $d->kind === DependencyKind::Attribute)),
        );
        sort($targets);

        self::assertSame(['App\Inject', 'App\Route', 'App\Validate'], $targets);
    }

    public function testMultipleAttributesInOneGroupEachProduceAnEdge(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Foo {}
            class Bar {}

            #[Foo, Bar]
            class Controller {}
            PHP);

        $targets = array_map(static fn (Dependency $d): string => $d->to, $dependencies);
        sort($targets);

        self::assertSame(['App\Bar', 'App\Foo'], $targets);
    }

    public function testAttributeNameIsResolvedAgainstUseStatements(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            use App\Other\RouteAttribute as Route;

            #[Route]
            class Controller {}
            PHP);

        self::assertCount(1, $dependencies);
        self::assertSame('App\Other\RouteAttribute', $dependencies[0]->to);
    }

    public function testFreeFunctionAttributeProducesNoEdge(): void
    {
        $dependencies = $this->parse(<<<'PHP'
            <?php

            namespace App;

            class Route {}

            #[Route]
            function helper(): void
            {
            }
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
