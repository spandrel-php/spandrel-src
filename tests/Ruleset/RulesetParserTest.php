<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Ruleset;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;
use Spandrel\Spandrel\Ruleset\InlinePattern;
use Spandrel\Spandrel\Ruleset\Layer;
use Spandrel\Spandrel\Ruleset\ReservedTarget;
use Spandrel\Spandrel\Ruleset\Rule;
use Spandrel\Spandrel\Ruleset\RulesetParseException;
use Spandrel\Spandrel\Ruleset\RulesetParser;
use Spandrel\Spandrel\Ruleset\RuleVerb;
use Spandrel\Spandrel\Ruleset\TypeHierarchyTarget;

final class RulesetParserTest extends TestCase
{
    public function testParsesExplicitLayers(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            # Architecture

            Some prose that should be ignored entirely.

            ## Layers

            - **Domain**: `App\Domain\**`
            - **Infrastructure**: `App\Infrastructure\**`, `App\Legacy\**`

            ## Rules

            - `Domain` must not depend on `Infrastructure`
            MARKDOWN);

        self::assertCount(2, $ruleset->layers);

        self::assertSame('Domain', $ruleset->layers[0]->name);
        self::assertSame(['App\Domain\**'], $ruleset->layers[0]->patterns);

        self::assertSame('Infrastructure', $ruleset->layers[1]->name);
        self::assertSame(['App\Infrastructure\**', 'App\Legacy\**'], $ruleset->layers[1]->patterns);

        self::assertCount(1, $ruleset->rules);
        self::assertSame('Domain', $ruleset->rules[0]->subject);
        self::assertSame(RuleVerb::MustNotDependOn, $ruleset->rules[0]->verb);
        self::assertSame('Infrastructure', $ruleset->rules[0]->object);
    }

    public function testParsesMayOnlyDependOnRule(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Infrastructure**: `App\Infrastructure\**`

            ## Rules

            - `Domain` may only depend on `Infrastructure`
            MARKDOWN);

        self::assertEquals(
            [new Rule('Domain', RuleVerb::MayOnlyDependOn, 'Infrastructure')],
            $ruleset->rules,
        );
    }

    public function testExpandsListsOnBothSidesAsCartesianProduct(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **A**: `App\A\**`
            - **B**: `App\B\**`
            - **C**: `App\C\**`
            - **D**: `App\D\**`

            ## Rules

            - `A` or `B` must not depend on `C` and `D`
            MARKDOWN);

        self::assertEquals(
            [
                new Rule('A', RuleVerb::MustNotDependOn, 'C'),
                new Rule('A', RuleVerb::MustNotDependOn, 'D'),
                new Rule('B', RuleVerb::MustNotDependOn, 'C'),
                new Rule('B', RuleVerb::MustNotDependOn, 'D'),
            ],
            $ruleset->rules,
        );
    }

    public function testExpandsDependsOnNothingAgainstAllOtherLayers(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Graph**: `App\Graph\**`
            - **Config**: `App\Config\**`
            - **Loader**: `App\Loader\**`

            ## Rules

            - `Graph` depends on nothing
            MARKDOWN);

        self::assertEquals(
            [
                new Rule('Graph', RuleVerb::MustNotDependOn, 'Config'),
                new Rule('Graph', RuleVerb::MustNotDependOn, 'Loader'),
            ],
            $ruleset->rules,
        );
    }

    public function testMayDependOnAnythingProducesNoRulesButIsRecordedAsUnconstrained(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Console**: `App\Console\**`
            - **Domain**: `App\Domain\**`

            ## Rules

            - `Console` may depend on anything
            MARKDOWN);

        self::assertSame([], $ruleset->rules);
        self::assertSame(['Console'], $ruleset->unconstrainedLayers);
    }

    public function testThrowsOnMalformedRuleBullet(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('line 7');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` must not depend `Infrastructure`
            MARKDOWN);
    }

    public function testMalformedRuleBulletErrorMentionsKindScopedVerbsToo(): void
    {
        // "must not call `X`" (missing "functions defined in") matches none of the
        // unscoped, nullary, or kind-scoped patterns — the fallback error should still
        // point a reader attempting a kind-scoped rule at the right grammar, not just
        // the unscoped one.
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('kind-scoped verb');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` must not call `App\Infrastructure\**`
            MARKDOWN);
    }

    public function testThrowsOnUnknownLayerInRule(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('Infrastructure');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` must not depend on `Infrastructure`
            MARKDOWN);
    }

    public function testThrowsOnConflictingRuleMode(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('Domain');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Infrastructure**: `App\Infrastructure\**`
            - **Console**: `App\Console\**`

            ## Rules

            - `Domain` must not depend on `Infrastructure`
            - `Domain` may only depend on `Console`
            MARKDOWN);
    }

    public function testIgnoresContentOutsideLayersSection(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Notes

            - **NotALayer**: `App\**`
            MARKDOWN);

        self::assertSame([], $ruleset->layers);
    }

    public function testEmptyRulesetHasNoLayers(): void
    {
        $ruleset = (new RulesetParser())->parse('# Empty');

        self::assertSame([], $ruleset->layers);
    }

    public function testThrowsOnMalformedBullet(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('line 3');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - Domain must not depend Infrastructure
            MARKDOWN);
    }

    public function testThrowsOnDuplicateLayerName(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('Domain');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Domain**: `App\Other\**`
            MARKDOWN);
    }

    public function testParsesCallScopedRules(): void
    {
        $mustNot = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Util**: `App\Util\**`

            ## Rules

            - `Domain` must not call functions defined in `Util`
            MARKDOWN);

        self::assertEquals(
            [new Rule('Domain', RuleVerb::MustNotDependOn, 'Util', DependencyKind::Call)],
            $mustNot->rules,
        );

        $mayOnly = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Util**: `App\Util\**`

            ## Rules

            - `Domain` may only call functions defined in `Util`
            MARKDOWN);

        self::assertEquals(
            [new Rule('Domain', RuleVerb::MayOnlyDependOn, 'Util', DependencyKind::Call)],
            $mayOnly->rules,
        );
    }

    public function testParsesInstantiateScopedRulesWithAnObject(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Util**: `App\Util\**`

            ## Rules

            - `Domain` must not instantiate objects from `Util`
            MARKDOWN);

        self::assertEquals(
            [new Rule('Domain', RuleVerb::MustNotDependOn, 'Util', DependencyKind::Instantiate)],
            $ruleset->rules,
        );
    }

    public function testParsesBareMustNotInstantiateObjects(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` must not instantiate objects
            MARKDOWN);

        self::assertEquals(
            [new Rule('Domain', RuleVerb::MustNotDependOn, null, DependencyKind::Instantiate)],
            $ruleset->rules,
        );
    }

    public function testParsesThrowScopedRules(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Util**: `App\Util\**`

            ## Rules

            - `Domain` may only throw `Util`
            MARKDOWN);

        self::assertEquals(
            [new Rule('Domain', RuleVerb::MayOnlyDependOn, 'Util', DependencyKind::Throw)],
            $ruleset->rules,
        );
    }

    public function testParsesCoreFunctionsReservedTarget(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Util**: `App\Util\**`

            ## Rules

            - `Domain` may only call functions defined in `Util` or core functions
            MARKDOWN);

        self::assertEquals(
            [
                new Rule('Domain', RuleVerb::MayOnlyDependOn, 'Util', DependencyKind::Call),
                new Rule('Domain', RuleVerb::MayOnlyDependOn, ReservedTarget::CoreFunctions, DependencyKind::Call),
            ],
            $ruleset->rules,
        );
    }

    public function testParsesCoreClassesReservedTarget(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Util**: `App\Util\**`

            ## Rules

            - `Domain` may only instantiate objects from `Util` or core classes
            MARKDOWN);

        self::assertEquals(
            [
                new Rule('Domain', RuleVerb::MayOnlyDependOn, 'Util', DependencyKind::Instantiate),
                new Rule('Domain', RuleVerb::MayOnlyDependOn, ReservedTarget::CoreClasses, DependencyKind::Instantiate),
            ],
            $ruleset->rules,
        );
    }

    public function testThrowsWhenCoreFunctionsUsedOutsideCallScope(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('call-scoped');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Util**: `App\Util\**`

            ## Rules

            - `Domain` may only throw `Util` or core functions
            MARKDOWN);
    }

    public function testThrowsWhenCoreClassesUsedOutsideInstantiateOrThrowScope(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('instantiate- or throw-scoped');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Util**: `App\Util\**`

            ## Rules

            - `Domain` may only call functions defined in `Util` or core classes
            MARKDOWN);
    }

    public function testParsesSubtypesOfOnAnUnscopedRule(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` must not depend on subtypes of `App\Legacy\BadBase`
            MARKDOWN);

        self::assertEquals(
            [new Rule('Domain', RuleVerb::MustNotDependOn, new TypeHierarchyTarget('App\Legacy\BadBase'))],
            $ruleset->rules,
        );
    }

    public function testParsesSubtypesOfOnAThrowScopedRule(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` may only throw subtypes of `App\Exception\Base`
            MARKDOWN);

        self::assertEquals(
            [new Rule('Domain', RuleVerb::MayOnlyDependOn, new TypeHierarchyTarget('App\Exception\Base'), DependencyKind::Throw)],
            $ruleset->rules,
        );
    }

    public function testParsesSubtypesOfInAnExceptClause(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` may only throw `App\Exception\**` except subtypes of `App\Exception\Ignored`
            MARKDOWN);

        self::assertEquals(
            [new Rule(
                'Domain',
                RuleVerb::MayOnlyDependOn,
                new InlinePattern('App\Exception\**'),
                DependencyKind::Throw,
                [new TypeHierarchyTarget('App\Exception\Ignored')],
            )],
            $ruleset->rules,
        );
    }

    public function testParsesSubtypesOfCombinedWithAnElementKindFilter(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` must not depend on classes in subtypes of `App\Legacy\BadBase`
            MARKDOWN);

        self::assertEquals(
            [new Rule(
                'Domain',
                RuleVerb::MustNotDependOn,
                new TypeHierarchyTarget('App\Legacy\BadBase'),
                objectElementKinds: [ElementKind::ClassLike],
            )],
            $ruleset->rules,
        );
    }

    public function testParsesSubtypesOfAsPartOfAList(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` may only throw subtypes of `App\Exception\Base` or subtypes of `App\Exception\OtherBase`
            MARKDOWN);

        self::assertEquals(
            [
                new Rule('Domain', RuleVerb::MayOnlyDependOn, new TypeHierarchyTarget('App\Exception\Base'), DependencyKind::Throw),
                new Rule('Domain', RuleVerb::MayOnlyDependOn, new TypeHierarchyTarget('App\Exception\OtherBase'), DependencyKind::Throw),
            ],
            $ruleset->rules,
        );
    }

    public function testThrowsWhenSubtypesOfUsedOnACallScopedRule(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('call-scoped');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` may only call functions defined in subtypes of `App\Legacy\BadBase`
            MARKDOWN);
    }

    public function testThrowsWhenSubtypesOfUsedInACallScopedExceptClause(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('call-scoped');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` may only call functions defined in `App\Util\**` except subtypes of `App\Legacy\BadBase`
            MARKDOWN);
    }

    public function testUnscopedAndKindScopedRulesCoexistForTheSameSubject(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Util**: `App\Util\**`

            ## Rules

            - `Domain` may only depend on `Util`
            - `Domain` must not instantiate objects from `Util`
            MARKDOWN);

        self::assertEquals(
            [
                new Rule('Domain', RuleVerb::MayOnlyDependOn, 'Util'),
                new Rule('Domain', RuleVerb::MustNotDependOn, 'Util', DependencyKind::Instantiate),
            ],
            $ruleset->rules,
        );
    }

    public function testThrowsOnConflictingModeWithinTheSameKindScope(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('Domain');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Util**: `App\Util\**`
            - **Other**: `App\Other\**`

            ## Rules

            - `Domain` must not instantiate objects from `Util`
            - `Domain` may only instantiate objects from `Other`
            MARKDOWN);
    }

    public function testInlinePatternAsSubject(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Util**: `App\Util\**`

            ## Rules

            - `App\Foo\**` must not depend on `Util`
            MARKDOWN);

        self::assertEquals(
            [new Rule(new InlinePattern('App\Foo\**'), RuleVerb::MustNotDependOn, 'Util')],
            $ruleset->rules,
        );
    }

    public function testInlinePatternAsObject(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` must not depend on `App\Vendor\**`
            MARKDOWN);

        self::assertEquals(
            [new Rule('Domain', RuleVerb::MustNotDependOn, new InlinePattern('App\Vendor\**'))],
            $ruleset->rules,
        );
    }

    public function testInlinePatternAsBothSubjectAndObject(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Rules

            - `App\Foo\**` may only depend on `App\Bar\**`
            MARKDOWN);

        self::assertEquals(
            [new Rule(new InlinePattern('App\Foo\**'), RuleVerb::MayOnlyDependOn, new InlinePattern('App\Bar\**'))],
            $ruleset->rules,
        );
    }

    public function testWildcardFreeSingleClassPattern(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Rules

            - `App\Domain\**` must not instantiate objects from `App\Factory\ConstraintFactory`
            MARKDOWN);

        self::assertEquals(
            [new Rule(
                new InlinePattern('App\Domain\**'),
                RuleVerb::MustNotDependOn,
                new InlinePattern('App\Factory\ConstraintFactory'),
                DependencyKind::Instantiate,
            )],
            $ruleset->rules,
        );
    }

    public function testExceptOnAnInlinePatternObject(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Rules

            - `App\Foo\**` may only depend on `App\Bar\**` except `App\Bar\Baz`
            MARKDOWN);

        self::assertEquals(
            [new Rule(
                new InlinePattern('App\Foo\**'),
                RuleVerb::MayOnlyDependOn,
                new InlinePattern('App\Bar\**'),
                null,
                [new InlinePattern('App\Bar\Baz')],
            )],
            $ruleset->rules,
        );
    }

    public function testExceptOnADeclaredLayerObject(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Bar**: `App\Bar\**`

            ## Rules

            - `App\Foo\**` may only depend on `Bar` except `App\Bar\Baz`
            MARKDOWN);

        self::assertEquals(
            [new Rule(
                new InlinePattern('App\Foo\**'),
                RuleVerb::MayOnlyDependOn,
                'Bar',
                null,
                [new InlinePattern('App\Bar\Baz')],
            )],
            $ruleset->rules,
        );
    }

    public function testExceptCombinedWithAListIsRejected(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('not supported');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **A**: `App\A\**`
            - **B**: `App\B\**`

            ## Rules

            - `App\Foo\**` must not depend on `A` and `B` except `App\A\X`
            MARKDOWN);
    }

    public function testExceptAcceptsAListOfOperands(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Rules

            - `App\Foo\**` may only depend on `App\Bar\**` except `App\Bar\Legacy\**`, `App\Bar\Deprecated\**`
            MARKDOWN);

        self::assertEquals(
            [new Rule(
                new InlinePattern('App\Foo\**'),
                RuleVerb::MayOnlyDependOn,
                new InlinePattern('App\Bar\**'),
                null,
                [new InlinePattern('App\Bar\Legacy\**'), new InlinePattern('App\Bar\Deprecated\**')],
            )],
            $ruleset->rules,
        );
    }

    public function testExceptAcceptsAnOrJoinedList(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **A**: `App\A\**`
            - **B**: `App\B\**`

            ## Rules

            - `App\Foo\**` may only depend on `App\Bar\**` except `A` or `B`
            MARKDOWN);

        self::assertEquals(['A', 'B'], $ruleset->rules[0]->objectExcept);
    }

    public function testExceptDoesNotNest(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('does not nest');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Rules

            - `App\Foo\**` may only depend on `App\Bar\**` except `App\Bar\Legacy\**` except `App\Bar\Legacy\New\**`
            MARKDOWN);
    }

    public function testKindScopedRuleWithInlinePattern(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Rules

            - `App\Domain\**` must not call functions defined in `App\Legacy\**`
            MARKDOWN);

        self::assertEquals(
            [new Rule(
                new InlinePattern('App\Domain\**'),
                RuleVerb::MustNotDependOn,
                new InlinePattern('App\Legacy\**'),
                DependencyKind::Call,
            )],
            $ruleset->rules,
        );
    }

    public function testNullaryPredicateRejectsAPatternSubject(): void
    {
        $this->expectException(RulesetParseException::class);

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `App\Foo\**` depends on nothing
            MARKDOWN);
    }

    public function testSameInlinePatternSubjectCoexistsAcrossDifferentModes(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **A**: `App\A\**`
            - **B**: `App\B\**`

            ## Rules

            - `App\Foo\**` must not depend on `A`
            - `App\Foo\**` may only depend on `B`
            MARKDOWN);

        self::assertEquals(
            [
                new Rule(new InlinePattern('App\Foo\**'), RuleVerb::MustNotDependOn, 'A'),
                new Rule(new InlinePattern('App\Foo\**'), RuleVerb::MayOnlyDependOn, 'B'),
            ],
            $ruleset->rules,
        );
    }

    public function testLayerExceptByPattern(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Y**: `App\Y\**` except `App\Y\Exception\**`
            MARKDOWN);

        self::assertEquals(
            [new Layer('Y', ['App\Y\**'], ['App\Y\Exception\**'])],
            $ruleset->layers,
        );
    }

    public function testLayerExceptByLayerNameResolvesToThatLayersRawPatterns(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Exception**: `App\*\Exception\**`
            - **Y**: `App\Y\**` except `Exception`
            MARKDOWN);

        self::assertEquals(
            [
                new Layer('Exception', ['App\*\Exception\**']),
                new Layer('Y', ['App\Y\**'], ['App\*\Exception\**']),
            ],
            $ruleset->layers,
        );
    }

    public function testLayerExceptWithMultipleTargets(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Y**: `App\Y\**` except `App\Y\A\**`, `App\Y\B\**`
            MARKDOWN);

        self::assertEquals(
            [new Layer('Y', ['App\Y\**'], ['App\Y\A\**', 'App\Y\B\**'])],
            $ruleset->layers,
        );
    }

    public function testUnknownLayerInLayerExceptThrows(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('Ghost');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Y**: `App\Y\**` except `Ghost`
            MARKDOWN);
    }

    public function testTwoLayerExceptCycleThrows(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('cycle');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Y**: `App\Y\**` except `Exception`
            - **Exception**: `App\*\Exception\**` except `Y`
            MARKDOWN);
    }

    public function testThreeLayerExceptCycleThrows(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('cycle');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **A**: `App\A\**` except `B`
            - **B**: `App\B\**` except `C`
            - **C**: `App\C\**` except `A`
            MARKDOWN);
    }

    public function testLayerExceptChainWithoutACycleParsesFine(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **A**: `App\A\**` except `B`
            - **B**: `App\B\**` except `C`
            - **C**: `App\C\**`
            MARKDOWN);

        self::assertEquals(
            [
                new Layer('A', ['App\A\**'], ['App\B\**']),
                new Layer('B', ['App\B\**'], ['App\C\**']),
                new Layer('C', ['App\C\**']),
            ],
            $ruleset->layers,
        );
    }

    public function testSelfExceptThrowsAsACycle(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('cycle');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Foo**: `App\Foo\**` except `Foo`
            MARKDOWN);
    }

    public function testGroupFlattensMemberPatterns(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Bar**: `App\Bar\**`
            - **Baz**: `App\Baz\**`
            - **Foo** groups `Bar` and `Baz`
            MARKDOWN);

        $bar = new Layer('Bar', ['App\Bar\**']);
        $baz = new Layer('Baz', ['App\Baz\**']);
        $foo = new Layer('Foo', ['App\Bar\**', 'App\Baz\**'], [], isGroup: true, members: [$bar, $baz]);

        self::assertEquals([$bar, $baz, $foo], $ruleset->layers);
    }

    public function testNestedGroupsFlattenTransitively(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Bar**: `App\Bar\**`
            - **Qux**: `App\Qux\**`
            - **Foo** groups `Bar`
            - **Mega** groups `Foo` and `Qux`
            MARKDOWN);

        $mega = $ruleset->layers[3];

        self::assertSame('Mega', $mega->name);
        self::assertTrue($mega->isGroup);
        self::assertSame(['App\Bar\**', 'App\Qux\**'], $mega->patterns);
        self::assertCount(2, $mega->members);
    }

    public function testGroupCycleIsDetected(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('cycle');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Foo** groups `Bar`
            - **Bar** groups `Foo`
            MARKDOWN);
    }

    public function testUnknownLayerInGroupThrows(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('Ghost');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Foo** groups `Ghost`
            MARKDOWN);
    }

    public function testGroupWithItsOwnExcept(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Bar**: `App\Bar\**`
            - **Baz**: `App\Baz\**`
            - **Foo** groups `Bar` and `Baz` except `App\Bar\Legacy\**`
            MARKDOWN);

        $foo = $ruleset->layers[2];

        self::assertSame(['App\Bar\Legacy\**'], $foo->except);
    }

    public function testExternalLayerIsMarkedExternalAndNeedsNoExplicitPatternColon(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **SymfonyConsole** matches `Symfony\Component\Console\**`
            MARKDOWN);

        self::assertEquals(
            [new Layer('SymfonyConsole', ['Symfony\Component\Console\**'], isExternal: true)],
            $ruleset->layers,
        );
    }

    public function testExternalLayerAcceptsMultiplePatterns(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Symfony** matches `Symfony\Component\Console\**`, `Symfony\Component\HttpFoundation\**`
            MARKDOWN);

        self::assertSame(
            ['Symfony\Component\Console\**', 'Symfony\Component\HttpFoundation\**'],
            $ruleset->layers[0]->patterns,
        );
    }

    public function testExternalLayerWithItsOwnExcept(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **SymfonyConsole** matches `Symfony\Component\Console\**` except `Symfony\Component\Console\Command\Command`
            MARKDOWN);

        self::assertSame(['Symfony\Component\Console\Command\Command'], $ruleset->layers[0]->except);
    }

    public function testExternalLayerNameMustBeUniqueAmongAllLayerKinds(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('Symfony');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Symfony**: `App\Symfony\**`
            - **Symfony** matches `Symfony\Component\Console\**`
            MARKDOWN);
    }

    public function testExceptCycleBetweenAnExternalAndAnExplicitLayerIsDetected(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('cycle');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Foo**: `App\Foo\**` except `Symfony`
            - **Symfony** matches `Symfony\**` except `Foo`
            MARKDOWN);
    }

    public function testGroupCanContainAnExternalLayer(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Bar**: `App\Bar\**`
            - **SymfonyConsole** matches `Symfony\Component\Console\**`
            - **Foo** groups `Bar` and `SymfonyConsole`
            MARKDOWN);

        $foo = $ruleset->layers[2];

        self::assertSame(['App\Bar\**', 'Symfony\Component\Console\**'], $foo->patterns);
    }

    public function testPlaceholderDerivesOneLayerPerDistinctCapturedSegment(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - `App\{Module}\**`
            MARKDOWN, $this->elements(
            'App\Domain\User',
            'App\Domain\Order',
            'App\Infrastructure\PdoUserRepository',
        ));

        self::assertCount(2, $ruleset->layers);
        self::assertSame('Domain', $ruleset->layers[0]->name);
        self::assertSame(['App\Domain\**'], $ruleset->layers[0]->patterns);
        self::assertSame('Infrastructure', $ruleset->layers[1]->name);
        self::assertSame(['App\Infrastructure\**'], $ruleset->layers[1]->patterns);
    }

    public function testPlaceholderColliesWithAnExplicitLayerThrows(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('Domain');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Custom\**`
            - `App\{Module}\**`
            MARKDOWN, $this->elements('App\Domain\User'));
    }

    public function testTwoCapturePlaceholderDerivesOneLeafPerCombination(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - `App\{Module}\{Layer}\**`
            MARKDOWN, $this->elements(
            'App\Billing\Domain\Invoice',
            'App\Billing\Infrastructure\PdoInvoiceRepository',
            'App\Shipping\Domain\Shipment',
        ));

        $leafNames = array_map(
            static fn (Layer $layer): string => $layer->name,
            array_values(array_filter($ruleset->layers, static fn (Layer $layer): bool => !$layer->isGroup)),
        );

        self::assertSame(['Billing_Domain', 'Billing_Infrastructure', 'Shipping_Domain'], $leafNames);

        $billingDomain = $ruleset->layers[0];
        self::assertSame('Billing_Domain', $billingDomain->name);
        self::assertSame(['App\Billing\Domain\**'], $billingDomain->patterns);
    }

    public function testTwoCapturePlaceholderDerivesAGroupPerAxisValue(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - `App\{Module}\{Layer}\**`
            MARKDOWN, $this->elements(
            'App\Billing\Domain\Invoice',
            'App\Billing\Infrastructure\PdoInvoiceRepository',
            'App\Shipping\Domain\Shipment',
        ));

        $groups = [];

        foreach ($ruleset->layers as $layer) {
            if ($layer->isGroup) {
                $groups[$layer->name] = array_map(static fn (Layer $member): string => $member->name, $layer->members);
            }
        }

        self::assertSame([
            'Billing' => ['Billing_Domain', 'Billing_Infrastructure'],
            'Shipping' => ['Shipping_Domain'],
            'Domain' => ['Billing_Domain', 'Shipping_Domain'],
            'Infrastructure' => ['Billing_Infrastructure'],
        ], $groups);
    }

    public function testRuleReferencingATwoCaptureAxisGroupMatchesAcrossEveryModule(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - `App\{Module}\{Layer}\**`

            ## Rules

            - `Domain` must not depend on `Infrastructure`
            MARKDOWN, $this->elements(
            'App\Billing\Domain\Invoice',
            'App\Billing\Infrastructure\PdoInvoiceRepository',
            'App\Shipping\Domain\Shipment',
            'App\Shipping\Infrastructure\PdoShipmentRepository',
        ));

        $domainGroup = null;

        foreach ($ruleset->layers as $layer) {
            if ($layer->name === 'Domain') {
                $domainGroup = $layer;

                break;
            }
        }

        self::assertNotNull($domainGroup);
        self::assertTrue($domainGroup->containsLeaf('Billing_Domain'));
        self::assertTrue($domainGroup->containsLeaf('Shipping_Domain'));
        self::assertCount(1, $ruleset->rules);
        self::assertSame('Domain', $ruleset->rules[0]->subject);
    }

    public function testTwoCapturePlaceholderCollisionWithAnExplicitLayerThrows(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('Billing_Domain');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Billing_Domain**: `App\Custom\**`
            - `App\{Module}\{Layer}\**`
            MARKDOWN, $this->elements('App\Billing\Domain\Invoice'));
    }

    public function testPlaceholderWithThreeCapturesIsRejected(): void
    {
        $this->expectException(RulesetParseException::class);

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - `App\{Module}\{Layer}\{Sub}\**`
            MARKDOWN, $this->elements('App\Billing\Domain\Sub\User'));
    }

    public function testDoubleWildcardBetweenTwoPlaceholderCapturesIsRejected(): void
    {
        $this->expectException(RulesetParseException::class);

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - `App\{Module}\**\{Layer}\**`
            MARKDOWN, $this->elements('App\Billing\Deep\Domain\User'));
    }

    public function testPlaceholderWithNoCaptureIsRejected(): void
    {
        $this->expectException(RulesetParseException::class);

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - `App\Domain\**`
            MARKDOWN, $this->elements('App\Domain\User'));
    }

    public function testDoubleWildcardBeforePlaceholderCaptureIsRejected(): void
    {
        $this->expectException(RulesetParseException::class);

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - `App\**\{Module}\**`
            MARKDOWN, $this->elements('App\Deep\Domain\User'));
    }

    public function testMixedExplicitAndPlaceholderLayersCoexist(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Shared**: `App\Shared\**`
            - `App\Modules\{Module}\**`
            MARKDOWN, $this->elements(
            'App\Shared\Config',
            'App\Modules\Domain\User',
        ));

        self::assertCount(2, $ruleset->layers);
        self::assertSame('Shared', $ruleset->layers[0]->name);
        self::assertSame('Domain', $ruleset->layers[1]->name);
        self::assertSame(['App\Modules\Domain\**'], $ruleset->layers[1]->patterns);
    }

    public function testRuleReferencingAPlaceholderDerivedNameResolves(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - `App\{Module}\**`

            ## Rules

            - `Domain` must not depend on `Infrastructure`
            MARKDOWN, $this->elements(
            'App\Domain\User',
            'App\Infrastructure\PdoUserRepository',
        ));

        self::assertCount(1, $ruleset->rules);
        self::assertSame('Domain', $ruleset->rules[0]->subject);
    }

    public function testElementKindFilterOnObject(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`

            ## Rules

            - `Domain` may only depend on interfaces in `Symfony\**`
            MARKDOWN);

        self::assertEquals(
            [new Rule(
                'Domain',
                RuleVerb::MayOnlyDependOn,
                new InlinePattern('Symfony\**'),
                null,
                null,
                null,
                [ElementKind::Interface],
            )],
            $ruleset->rules,
        );
    }

    public function testElementKindFilterOnSubject(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Infrastructure**: `App\Infrastructure\**`

            ## Rules

            - interfaces in `App\**` must not depend on `Infrastructure`
            MARKDOWN);

        self::assertEquals(
            [new Rule(
                new InlinePattern('App\**'),
                RuleVerb::MustNotDependOn,
                'Infrastructure',
                null,
                null,
                [ElementKind::Interface],
            )],
            $ruleset->rules,
        );
    }

    public function testElementKindFilterWithTwoKindsJoinedByAnd(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            ## Rules

            - `App\**` may only depend on classes and interfaces in `Symfony\**`
            MARKDOWN);

        self::assertSame([ElementKind::ClassLike, ElementKind::Interface], $ruleset->rules[0]->objectElementKinds);
    }

    public function testElementKindFilterWithThreeKindsJoinedByCommaAndAnd(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            ## Rules

            - `App\**` may only depend on classes, interfaces, and enums in `Symfony\**`
            MARKDOWN);

        self::assertSame(
            [ElementKind::ClassLike, ElementKind::Interface, ElementKind::Enum],
            $ruleset->rules[0]->objectElementKinds,
        );
    }

    public function testElementKindFilterCombinedWithExcept(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            ## Rules

            - `App\**` may only depend on interfaces in `Symfony\**` except `Symfony\Legacy\**`
            MARKDOWN);

        $rule = $ruleset->rules[0];

        self::assertSame([ElementKind::Interface], $rule->objectElementKinds);
        self::assertEquals([new InlinePattern('Symfony\Legacy\**')], $rule->objectExcept);
    }

    public function testTextResemblingAKindFilterButNotOneOfTheFourKeywordsIsIgnoredAsProse(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            ## Rules

            - things in `App\**` may only depend on `App\Other\**`
            MARKDOWN);

        self::assertNull($ruleset->rules[0]->subjectElementKinds);
    }

    public function testParseAllMergesLayersFromMultipleFiles(): void
    {
        $ruleset = (new RulesetParser())->parseAll([
            ['a.md', <<<'MARKDOWN'
                ## Layers

                - **Domain**: `App\Domain\**`
                MARKDOWN],
            ['b.md', <<<'MARKDOWN'
                ## Layers

                - **Infrastructure**: `App\Infrastructure\**`
                MARKDOWN],
        ]);

        $names = array_map(static fn (Layer $layer): string => $layer->name, $ruleset->layers);

        self::assertSame(['Domain', 'Infrastructure'], $names);
    }

    public function testParseAllLetsARuleInOneFileReferenceALayerDeclaredInAnother(): void
    {
        $ruleset = (new RulesetParser())->parseAll([
            ['layers.md', <<<'MARKDOWN'
                ## Layers

                - **Domain**: `App\Domain\**`
                - **Infrastructure**: `App\Infrastructure\**`
                MARKDOWN],
            ['rules.md', <<<'MARKDOWN'
                ## Rules

                - `Domain` must not depend on `Infrastructure`
                MARKDOWN],
        ]);

        self::assertCount(1, $ruleset->rules);
        self::assertSame('Domain', $ruleset->rules[0]->subject);
    }

    public function testMetaSectionAbsentDefaultsToAllFalse(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            MARKDOWN);

        self::assertFalse($ruleset->meta->strictElements);
        self::assertFalse($ruleset->meta->strictParsing);
        self::assertFalse($ruleset->meta->strictLayers);
    }

    public function testMetaStrictElementsSentenceParses(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Meta

            - Any class not in a layer violates rules.
            MARKDOWN);

        self::assertTrue($ruleset->meta->strictElements);
        self::assertFalse($ruleset->meta->strictParsing);
        self::assertFalse($ruleset->meta->strictLayers);
    }

    public function testMetaStrictParsingSentenceParses(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Meta

            - A file that fails to parse violates rules.
            MARKDOWN);

        self::assertFalse($ruleset->meta->strictElements);
        self::assertTrue($ruleset->meta->strictParsing);
        self::assertFalse($ruleset->meta->strictLayers);
    }

    public function testMetaStrictLayersSentenceParses(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Meta

            - Every layer must be used in a rule.
            MARKDOWN);

        self::assertFalse($ruleset->meta->strictElements);
        self::assertFalse($ruleset->meta->strictParsing);
        self::assertTrue($ruleset->meta->strictLayers);
    }

    public function testAllThreeMetaSentencesParseTogether(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Meta

            - Any class not in a layer violates rules.
            - A file that fails to parse violates rules.
            - Every layer must be used in a rule.
            MARKDOWN);

        self::assertTrue($ruleset->meta->strictElements);
        self::assertTrue($ruleset->meta->strictParsing);
        self::assertTrue($ruleset->meta->strictLayers);
    }

    public function testUnknownMetaBulletThrows(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('meta bullet');

        (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Meta

            - Nonsense policy.
            MARKDOWN);
    }

    public function testMetaSentencesOrMergeAcrossRulesetFiles(): void
    {
        $ruleset = (new RulesetParser())->parseAll([
            ['a.md', <<<'MARKDOWN'
                ## Meta

                - Any class not in a layer violates rules.
                MARKDOWN],
            ['b.md', <<<'MARKDOWN'
                ## Meta

                - Every layer must be used in a rule.
                MARKDOWN],
        ]);

        self::assertTrue($ruleset->meta->strictElements);
        self::assertFalse($ruleset->meta->strictParsing);
        self::assertTrue($ruleset->meta->strictLayers);
    }

    public function testParseAllThrowsOnADuplicateLayerNameAcrossFiles(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('already declared');

        (new RulesetParser())->parseAll([
            ['a.md', <<<'MARKDOWN'
                ## Layers

                - **Domain**: `App\Domain\**`
                MARKDOWN],
            ['b.md', <<<'MARKDOWN'
                ## Layers

                - **Domain**: `App\Other\**`
                MARKDOWN],
        ]);
    }

    public function testParseAllThrowsOnAConflictingRuleModeAcrossFiles(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('conflicting rule mode');

        (new RulesetParser())->parseAll([
            ['layers.md', <<<'MARKDOWN'
                ## Layers

                - **Domain**: `App\Domain\**`
                - **A**: `App\A\**`
                - **B**: `App\B\**`
                MARKDOWN],
            ['a.md', <<<'MARKDOWN'
                ## Rules

                - `Domain` must not depend on `A`
                MARKDOWN],
            ['b.md', <<<'MARKDOWN'
                ## Rules

                - `Domain` may only depend on `B`
                MARKDOWN],
        ]);
    }

    public function testParseAllErrorMessageIncludesTheOffendingFilePath(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('b.md: line 3');

        (new RulesetParser())->parseAll([
            ['a.md', <<<'MARKDOWN'
                ## Layers

                - **Domain**: `App\Domain\**`
                MARKDOWN],
            ['b.md', <<<'MARKDOWN'
                ## Layers

                - not a valid bullet at all
                MARKDOWN],
        ]);
    }

    public function testSingleFileParseErrorMessageHasNoFileLabel(): void
    {
        try {
            (new RulesetParser())->parse(<<<'MARKDOWN'
                ## Layers

                - not a valid bullet at all
                MARKDOWN);
            self::fail('Expected a RulesetParseException');
        } catch (RulesetParseException $e) {
            self::assertStringStartsWith('line 3:', $e->getMessage());
        }
    }

    /**
     * @return Element[]
     */
    private function elements(string ...$fqcns): array
    {
        return array_map(
            static fn (string $fqcn): Element => new Element($fqcn, ElementKind::ClassLike, 'file.php', 1),
            $fqcns,
        );
    }
}
