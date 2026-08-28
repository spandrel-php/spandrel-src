<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\RuleEngine;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;
use Spandrel\Spandrel\RuleEngine\RuleEngine;
use Spandrel\Spandrel\Ruleset\InlinePattern;
use Spandrel\Spandrel\Ruleset\Layer;
use Spandrel\Spandrel\Ruleset\LayerResolution;
use Spandrel\Spandrel\Ruleset\ReservedTarget;
use Spandrel\Spandrel\Ruleset\Rule;
use Spandrel\Spandrel\Ruleset\Ruleset;
use Spandrel\Spandrel\Ruleset\RuleVerb;
use Spandrel\Spandrel\Ruleset\TypeHierarchyTarget;

final class RuleEngineTest extends TestCase
{
    public function testMustNotDependOnProducesAViolation(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain', 'Infrastructure'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, 'Infrastructure')],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
            'Infrastructure' => [$this->element('App\Infrastructure\Bar')],
        ]);
        $dependency = new Dependency('App\Domain\Foo', 'App\Infrastructure\Bar', DependencyKind::Extends, 'Foo.php', 5);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
        $violation = $violations[0];
        self::assertSame('`Domain` must not depend on `Infrastructure`', $violation->rule);
        self::assertSame('App\Domain\Foo', $violation->fromElement);
        self::assertSame('App\Infrastructure\Bar', $violation->toElement);
        self::assertSame(DependencyKind::Extends, $violation->dependencyKind);
        self::assertSame('Foo.php', $violation->file);
        self::assertSame(5, $violation->line);
        self::assertSame(
            'App\Domain\Foo (Domain) must not depend on App\Infrastructure\Bar (Infrastructure) via extends',
            $violation->message,
        );
    }

    public function testMayOnlyDependOnAllowsTheDeclaredTarget(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('A', 'B'),
            rules: [new Rule('A', RuleVerb::MayOnlyDependOn, 'B')],
        );
        $resolution = $this->resolution([
            'A' => [$this->element('App\A\Foo')],
            'B' => [$this->element('App\B\Bar')],
        ]);
        $dependency = new Dependency('App\A\Foo', 'App\B\Bar', DependencyKind::Instantiate, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertSame([], $violations);
    }

    public function testMayOnlyDependOnRejectsAnUndeclaredTarget(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('A', 'B', 'C'),
            rules: [new Rule('A', RuleVerb::MayOnlyDependOn, 'B')],
        );
        $resolution = $this->resolution([
            'A' => [$this->element('App\A\Foo')],
            'B' => [$this->element('App\B\Bar')],
            'C' => [$this->element('App\C\Baz')],
        ]);
        $dependency = new Dependency('App\A\Foo', 'App\C\Baz', DependencyKind::Instantiate, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
        self::assertSame('`A` may only depend on `B`', $violations[0]->rule);
    }

    public function testMayOnlyDependOnAccumulatesMultipleBulletsIntoOneWhitelist(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('A', 'B', 'C'),
            rules: [
                new Rule('A', RuleVerb::MayOnlyDependOn, 'B'),
                new Rule('A', RuleVerb::MayOnlyDependOn, 'C'),
            ],
        );
        $resolution = $this->resolution([
            'A' => [$this->element('App\A\Foo')],
            'B' => [$this->element('App\B\Bar')],
            'C' => [$this->element('App\C\Baz')],
        ]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\A\Foo', 'App\B\Bar', DependencyKind::Instantiate, 'Foo.php', 1),
            new Dependency('App\A\Foo', 'App\C\Baz', DependencyKind::Instantiate, 'Foo.php', 2),
        ]);

        self::assertSame([], $violations);
    }

    public function testSameLayerEdgeIsAlwaysAllowedEvenIfARuleWouldOtherwiseMatch(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, 'Domain')],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo'), $this->element('App\Domain\Bar')],
        ]);
        $dependency = new Dependency('App\Domain\Foo', 'App\Domain\Bar', DependencyKind::Extends, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertSame([], $violations);
    }

    public function testEdgeToAnElementWithNoLayerIsSkipped(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain', 'Infrastructure'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, 'Infrastructure')],
        );
        $resolution = $this->resolution(['Domain' => [$this->element('App\Domain\Foo')]]);
        $dependency = new Dependency('App\Domain\Foo', 'Vendor\SomeLib\Thing', DependencyKind::ParamType, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertSame([], $violations);
    }

    public function testSubjectWithNoRulesNeverViolates(): void
    {
        $ruleset = new Ruleset(layers: [], rules: []);
        $resolution = $this->resolution([
            'Console' => [$this->element('App\Console\Foo')],
            'Domain' => [$this->element('App\Domain\Bar')],
        ]);
        $dependency = new Dependency('App\Console\Foo', 'App\Domain\Bar', DependencyKind::StaticCall, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertSame([], $violations);
    }

    public function testViolationsAreSortedByFileThenLine(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain', 'Infrastructure'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, 'Infrastructure')],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
            'Infrastructure' => [$this->element('App\Infrastructure\Bar')],
        ]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\Domain\Foo', 'App\Infrastructure\Bar', DependencyKind::Extends, 'Z.php', 1),
            new Dependency('App\Domain\Foo', 'App\Infrastructure\Bar', DependencyKind::Instantiate, 'A.php', 20),
            new Dependency('App\Domain\Foo', 'App\Infrastructure\Bar', DependencyKind::Instantiate, 'A.php', 5),
        ]);

        self::assertSame(
            [['A.php', 5], ['A.php', 20], ['Z.php', 1]],
            array_map(static fn ($v): array => [$v->file, $v->line], $violations),
        );
    }

    public function testCallScopedMustNotOnlyAppliesToCallEdges(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain', 'Infrastructure'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, 'Infrastructure', DependencyKind::Call)],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
            'Infrastructure' => [$this->element('App\Infrastructure\Bar')],
        ]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\Domain\Foo', 'App\Infrastructure\Bar', DependencyKind::Call, 'Foo.php', 1),
            new Dependency('App\Domain\Foo', 'App\Infrastructure\Bar', DependencyKind::Extends, 'Foo.php', 2),
        ]);

        self::assertCount(1, $violations);
        self::assertSame(DependencyKind::Call, $violations[0]->dependencyKind);
        self::assertSame('`Domain` must not call functions defined in `Infrastructure`', $violations[0]->rule);
    }

    public function testBareMustNotInstantiateObjectsViolatesUnconditionally(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, null, DependencyKind::Instantiate)],
        );
        $resolution = $this->resolution(['Domain' => [$this->element('App\Domain\Foo')]]);
        // No Element registered for the target at all — same as a vendor/unparsed class.
        $dependency = new Dependency('App\Domain\Foo', 'Vendor\SomeLib\Thing', DependencyKind::Instantiate, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
        self::assertSame('`Domain` must not instantiate objects', $violations[0]->rule);
    }

    public function testCoreFunctionsMatchesOnlyRealBuiltins(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, ReservedTarget::CoreFunctions, DependencyKind::Call)],
        );
        $resolution = $this->resolution(['Domain' => [$this->element('App\Domain\Foo')]]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\Domain\Foo', 'strlen', DependencyKind::Call, 'Foo.php', 1),
            new Dependency('App\Domain\Foo', 'App\Util\helper', DependencyKind::Call, 'Foo.php', 2),
        ]);

        self::assertCount(1, $violations);
        self::assertSame('strlen', $violations[0]->toElement);
        self::assertSame('`Domain` must not call functions defined in core functions', $violations[0]->rule);
    }

    public function testCoreClassesMatchesOnlyRealBuiltins(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, ReservedTarget::CoreClasses, DependencyKind::Instantiate)],
        );
        $resolution = $this->resolution(['Domain' => [$this->element('App\Domain\Foo')]]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\Domain\Foo', 'RuntimeException', DependencyKind::Instantiate, 'Foo.php', 1),
            new Dependency('App\Domain\Foo', 'App\Util\Widget', DependencyKind::Instantiate, 'Foo.php', 2),
        ]);

        self::assertCount(1, $violations);
        self::assertSame('RuntimeException', $violations[0]->toElement);
    }

    public function testUnresolvableCallEdgeViolatesAWhitelistCallScopedRule(): void
    {
        // "may only call functions defined in Util or core functions" — an unresolvable
        // call is neither, so it must not be an implicit, invisible pass.
        $ruleset = new Ruleset(
            layers: $this->layers('Domain', 'Util'),
            rules: [
                new Rule('Domain', RuleVerb::MayOnlyDependOn, 'Util', DependencyKind::Call),
                new Rule('Domain', RuleVerb::MayOnlyDependOn, ReservedTarget::CoreFunctions, DependencyKind::Call),
            ],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
            'Util' => [$this->element('App\Util\Helper')],
        ]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\Domain\Foo', Dependency::UNRESOLVABLE, DependencyKind::Call, 'Foo.php', 1),
        ]);

        self::assertCount(1, $violations);
        self::assertSame(Dependency::UNRESOLVABLE, $violations[0]->toElement);
        self::assertStringContainsString('an unresolvable target', $violations[0]->message);
        self::assertStringNotContainsString(Dependency::UNRESOLVABLE, $violations[0]->message);
    }

    public function testUnresolvableCallEdgeDoesNotViolateABlacklistCallScopedRule(): void
    {
        // "must not call functions defined in Util" — an unresolvable call can't be shown
        // to match Util, so a blacklist rule (which can only forbid what it can name)
        // correctly stays silent, same as for any other untracked call.
        $ruleset = new Ruleset(
            layers: $this->layers('Domain', 'Util'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, 'Util', DependencyKind::Call)],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
            'Util' => [$this->element('App\Util\Helper')],
        ]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\Domain\Foo', Dependency::UNRESOLVABLE, DependencyKind::Call, 'Foo.php', 1),
        ]);

        self::assertSame([], $violations);
    }

    public function testKindScopedRuleNarrowsWhatAnUnscopedRuleAllows(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain', 'Util'),
            rules: [
                new Rule('Domain', RuleVerb::MayOnlyDependOn, 'Util'),
                new Rule('Domain', RuleVerb::MustNotDependOn, 'Util', DependencyKind::Instantiate),
            ],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
            'Util' => [$this->element('App\Util\Bar')],
        ]);
        $dependency = new Dependency('App\Domain\Foo', 'App\Util\Bar', DependencyKind::Instantiate, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
        self::assertSame('`Domain` must not instantiate objects from `Util`', $violations[0]->rule);
    }

    public function testUnscopedRuleForbidsEvenWhenAKindScopedRuleWouldAllow(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain', 'Util'),
            rules: [
                new Rule('Domain', RuleVerb::MustNotDependOn, 'Util'),
                new Rule('Domain', RuleVerb::MayOnlyDependOn, 'Util', DependencyKind::Call),
            ],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
            'Util' => [$this->element('App\Util\Bar')],
        ]);
        $dependency = new Dependency('App\Domain\Foo', 'App\Util\Bar', DependencyKind::Call, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
        self::assertSame('`Domain` must not depend on `Util`', $violations[0]->rule);
    }

    public function testExceptOnTheUnscopedRuleIsTheEscapeHatchForAKindScopedException(): void
    {
        // The documented fix for "unscoped rule always wins" (issue #10): except the
        // specific target out of the unscoped rule's object, rather than wanting the
        // kind-scoped rule to override it directly. `Domain` must not depend on `Util`
        // at all, except `App\Util\SomeException` — which the throw-scoped rule then
        // governs on its own.
        $ruleset = new Ruleset(
            layers: $this->layers('Domain', 'Util'),
            rules: [
                new Rule('Domain', RuleVerb::MustNotDependOn, 'Util', null, [new InlinePattern('App\Util\SomeException')]),
                new Rule('Domain', RuleVerb::MayOnlyDependOn, new InlinePattern('App\Util\SomeException'), DependencyKind::Throw),
            ],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
            'Util' => [$this->element('App\Util\SomeException'), $this->element('App\Util\OtherThing')],
        ]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\Domain\Foo', 'App\Util\SomeException', DependencyKind::Throw, 'Foo.php', 1),
            new Dependency('App\Domain\Foo', 'App\Util\OtherThing', DependencyKind::Extends, 'Foo.php', 2),
        ]);

        self::assertCount(1, $violations);
        self::assertSame('App\Util\OtherThing', $violations[0]->toElement);
    }

    public function testInlinePatternSubjectMatchesAnElementWithNoDeclaredLayer(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Infrastructure'),
            rules: [new Rule(new InlinePattern('App\Legacy\**'), RuleVerb::MustNotDependOn, 'Infrastructure')],
        );
        $resolution = $this->resolution(['Infrastructure' => [$this->element('App\Infrastructure\Bar')]]);
        // App\Legacy\Foo has no Element/declared layer at all — only the subject pattern matches it.
        $dependency = new Dependency('App\Legacy\Foo', 'App\Infrastructure\Bar', DependencyKind::Extends, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
        self::assertSame('App\Legacy\Foo', $violations[0]->fromElement);
    }

    public function testExceptCarvesOutOfAnInlinePatternObject(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain'),
            rules: [new Rule(
                'Domain',
                RuleVerb::MayOnlyDependOn,
                new InlinePattern('App\Bar\**'),
                null,
                [new InlinePattern('App\Bar\Baz')],
            )],
        );
        $resolution = $this->resolution(['Domain' => [$this->element('App\Domain\Foo')]]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\Domain\Foo', 'App\Bar\Qux', DependencyKind::Extends, 'Foo.php', 1),
            new Dependency('App\Domain\Foo', 'App\Bar\Baz', DependencyKind::Extends, 'Foo.php', 2),
        ]);

        self::assertCount(1, $violations);
        self::assertSame('App\Bar\Baz', $violations[0]->toElement);
    }

    public function testExceptWithMultipleOperandsCarvesOutAnyMatch(): void
    {
        // object=Bar\**, except=[Baz, Qux]: "may only depend on Bar\** but not
        // specifically Baz or Qux" — a dependency on either excepted target is a
        // violation (carved out of the allowed set), same as the single-except case;
        // anything else matching Bar\** stays allowed.
        $ruleset = new Ruleset(
            layers: $this->layers('Domain'),
            rules: [new Rule(
                'Domain',
                RuleVerb::MayOnlyDependOn,
                new InlinePattern('App\Bar\**'),
                null,
                [new InlinePattern('App\Bar\Baz'), new InlinePattern('App\Bar\Qux')],
            )],
        );
        $resolution = $this->resolution(['Domain' => [$this->element('App\Domain\Foo')]]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\Domain\Foo', 'App\Bar\Baz', DependencyKind::Extends, 'Foo.php', 1),
            new Dependency('App\Domain\Foo', 'App\Bar\Qux', DependencyKind::Extends, 'Foo.php', 2),
            new Dependency('App\Domain\Foo', 'App\Bar\Allowed', DependencyKind::Extends, 'Foo.php', 3),
        ]);

        self::assertCount(2, $violations);
        self::assertSame(['App\Bar\Baz', 'App\Bar\Qux'], array_map(static fn ($v) => $v->toElement, $violations));
    }

    public function testExceptCarvesOutOfADeclaredLayerObject(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain', 'Infrastructure'),
            rules: [new Rule(
                'Domain',
                RuleVerb::MustNotDependOn,
                'Infrastructure',
                null,
                [new InlinePattern('App\Infrastructure\Legacy\**')],
            )],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
            'Infrastructure' => [$this->element('App\Infrastructure\Bar'), $this->element('App\Infrastructure\Legacy\Old')],
        ]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\Domain\Foo', 'App\Infrastructure\Bar', DependencyKind::Extends, 'Foo.php', 1),
            new Dependency('App\Domain\Foo', 'App\Infrastructure\Legacy\Old', DependencyKind::Extends, 'Foo.php', 2),
        ]);

        self::assertCount(1, $violations);
        self::assertSame('App\Infrastructure\Bar', $violations[0]->toElement);
    }

    public function testExternalLayerObjectMatchesAnEdgeWithNoResolvedElementAtAll(): void
    {
        $ruleset = new Ruleset(
            layers: [
                new Layer('Domain', ['App\Domain\**']),
                new Layer('SymfonyConsole', ['Symfony\Component\Console\**'], isExternal: true),
            ],
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, 'SymfonyConsole')],
        );
        $resolution = $this->resolution(['Domain' => [$this->element('App\Domain\Foo')]]);
        // No Element registered for Symfony\Component\Console\Command\Command at all —
        // same as vendor code Spandrel was never told to parse. A declared, non-external
        // layer object would silently skip this edge (see
        // testEdgeToAnElementWithNoLayerIsSkipped); an external layer must not.
        $dependency = new Dependency('App\Domain\Foo', 'Symfony\Component\Console\Command\Command', DependencyKind::Extends, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
        self::assertSame('`Domain` must not depend on `SymfonyConsole`', $violations[0]->rule);
    }

    public function testExternalLayerExceptSuppressesAMatch(): void
    {
        $ruleset = new Ruleset(
            layers: [
                new Layer('Domain', ['App\Domain\**']),
                new Layer(
                    'SymfonyConsole',
                    ['Symfony\Component\Console\**'],
                    except: ['Symfony\Component\Console\Command\Command'],
                    isExternal: true,
                ),
            ],
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, 'SymfonyConsole')],
        );
        $resolution = $this->resolution(['Domain' => [$this->element('App\Domain\Foo')]]);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [
            new Dependency('App\Domain\Foo', 'Symfony\Component\Console\Command\Command', DependencyKind::Extends, 'Foo.php', 1),
            new Dependency('App\Domain\Foo', 'Symfony\Component\Console\Output\Output', DependencyKind::Extends, 'Foo.php', 2),
        ]);

        self::assertCount(1, $violations);
        self::assertSame('Symfony\Component\Console\Output\Output', $violations[0]->toElement);
    }

    public function testElementKindFilterNeverMatchesAnExternalLayerTarget(): void
    {
        $ruleset = new Ruleset(
            layers: [
                new Layer('Domain', ['App\Domain\**']),
                new Layer('SymfonyConsole', ['Symfony\Component\Console\**'], isExternal: true),
            ],
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, 'SymfonyConsole', objectElementKinds: [ElementKind::Interface])],
        );
        $resolution = $this->resolution(['Domain' => [$this->element('App\Domain\Foo')]]);
        // Whether the vendor target is really an interface is unknowable without an
        // Element for it — the one case an external layer genuinely can't help with.
        $dependency = new Dependency('App\Domain\Foo', 'Symfony\Component\Console\Command\Command', DependencyKind::Extends, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertSame([], $violations);
    }

    public function testSameLayerBypassDoesNotApplyToASharedInlinePattern(): void
    {
        $ruleset = new Ruleset(
            layers: [],
            rules: [new Rule(
                new InlinePattern('App\Foo\**'),
                RuleVerb::MayOnlyDependOn,
                new InlinePattern('App\Bar\**'),
            )],
        );
        $resolution = $this->resolution([]);
        // Both sides only match via the same inline pattern (App\Foo\**) — no declared layer
        // covers either, so the declared-layer same-layer bypass must not apply here.
        $dependency = new Dependency('App\Foo\A', 'App\Foo\B', DependencyKind::Extends, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
    }

    public function testRuleTargetingAGroupMatchesAnyOfItsMembers(): void
    {
        $bar = new Layer('Bar', ['App\Bar\**']);
        $baz = new Layer('Baz', ['App\Baz\**']);
        $io = new Layer('IO', ['App\Bar\**', 'App\Baz\**'], [], isGroup: true, members: [$bar, $baz]);
        $domain = new Layer('Domain', ['App\Domain\**']);

        $ruleset = new Ruleset(
            layers: [$bar, $baz, $io, $domain],
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, 'IO')],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
            'Bar' => [$this->element('App\Bar\Thing')],
        ]);
        $dependency = new Dependency('App\Domain\Foo', 'App\Bar\Thing', DependencyKind::Extends, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
        self::assertSame('`Domain` must not depend on `IO`', $violations[0]->rule);
    }

    public function testSameLayerBypassUsesTheLeafLayerNotAContainingGroup(): void
    {
        $bar = new Layer('Bar', ['App\Bar\**']);
        $baz = new Layer('Baz', ['App\Baz\**']);
        $io = new Layer('IO', ['App\Bar\**', 'App\Baz\**'], [], isGroup: true, members: [$bar, $baz]);

        $ruleset = new Ruleset(
            layers: [$bar, $baz, $io],
            rules: [new Rule('IO', RuleVerb::MustNotDependOn, 'IO')],
        );
        $resolution = $this->resolution([
            'Bar' => [$this->element('App\Bar\Foo')],
            'Baz' => [$this->element('App\Baz\Thing')],
        ]);
        // Bar and Baz are different leaf layers, both members of IO — a rule where IO is
        // both subject and object must still flag this edge; the same-layer bypass only
        // exempts a *literal* leaf match (fromLayer === toLayer), not "both in one group."
        $dependency = new Dependency('App\Bar\Foo', 'App\Baz\Thing', DependencyKind::Extends, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
    }

    public function testLeafsOwnWhitelistIsNotOverriddenByAContainingGroupsUnrelatedBlacklist(): void
    {
        // Parser may only depend on Graph (leaf-level whitelist); Core (a group
        // containing Parser) must not depend on IO (a group-level blacklist whose
        // object, IO, is unrelated to this edge's target, Graph). Parser -> Graph is
        // explicitly allowed by Parser's own rule and untouched by Core's — this must
        // never become a violation, regardless of which rule happens to be declared
        // first (a real bug: evaluateEdgeAgainstGroup() used to read $group[0]->verb
        // for a whole batch of subject-matching rules, silently misattributing the
        // verb whenever a leaf's own rule and its containing group's rule had
        // different verbs and the group's rule was ordered first).
        $graph = new Layer('Graph', ['App\Graph\**']);
        $parser = new Layer('Parser', ['App\Parser\**']);
        $io = new Layer('IO', ['App\IO\**']);
        $core = new Layer('Core', ['App\Graph\**', 'App\Parser\**'], [], isGroup: true, members: [$graph, $parser]);

        $resolution = $this->resolution([
            'Graph' => [$this->element('App\Graph\Element')],
            'Parser' => [$this->element('App\Parser\Collector')],
        ]);
        $dependency = new Dependency('App\Parser\Collector', 'App\Graph\Element', DependencyKind::PropertyType, 'Collector.php', 1);

        $groupRuleFirst = new Ruleset(
            layers: [$graph, $parser, $io, $core],
            rules: [
                new Rule('Core', RuleVerb::MustNotDependOn, 'IO'),
                new Rule('Parser', RuleVerb::MayOnlyDependOn, 'Graph'),
            ],
        );
        self::assertCount(0, (new RuleEngine())->evaluate($groupRuleFirst, $resolution, [$dependency]));

        $leafRuleFirst = new Ruleset(
            layers: [$graph, $parser, $io, $core],
            rules: [
                new Rule('Parser', RuleVerb::MayOnlyDependOn, 'Graph'),
                new Rule('Core', RuleVerb::MustNotDependOn, 'IO'),
            ],
        );
        self::assertCount(0, (new RuleEngine())->evaluate($leafRuleFirst, $resolution, [$dependency]));
    }

    public function testElementKindFilterFlagsAConcreteClassTarget(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('A', 'B'),
            rules: [new Rule('A', RuleVerb::MayOnlyDependOn, 'B', objectElementKinds: [ElementKind::Interface])],
        );
        $resolution = $this->resolution([
            'A' => [$this->element('App\A\Foo')],
            'B' => [$this->element('App\B\ConcreteBar', ElementKind::ClassLike)],
        ]);
        $dependency = new Dependency('App\A\Foo', 'App\B\ConcreteBar', DependencyKind::Instantiate, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
    }

    public function testElementKindFilterAllowsAMatchingInterfaceTarget(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('A', 'B'),
            rules: [new Rule('A', RuleVerb::MayOnlyDependOn, 'B', objectElementKinds: [ElementKind::Interface])],
        );
        $resolution = $this->resolution([
            'A' => [$this->element('App\A\Foo')],
            'B' => [$this->element('App\B\BarInterface', ElementKind::Interface)],
        ]);
        $dependency = new Dependency('App\A\Foo', 'App\B\BarInterface', DependencyKind::Instantiate, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertSame([], $violations);
    }

    public function testElementKindFilterOnSubjectOnlyAppliesToMatchingElements(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('A', 'B'),
            rules: [new Rule('A', RuleVerb::MustNotDependOn, 'B', subjectElementKinds: [ElementKind::Interface])],
        );
        $resolution = $this->resolution([
            'A' => [$this->element('App\A\ConcreteFoo', ElementKind::ClassLike)],
            'B' => [$this->element('App\B\Bar')],
        ]);
        // ConcreteFoo is a class, not an interface — the subject filter excludes it, so
        // this dependency is simply not covered by the rule at all.
        $dependency = new Dependency('App\A\ConcreteFoo', 'App\B\Bar', DependencyKind::Extends, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertSame([], $violations);
    }

    public function testElementKindFilterCombinedWithExcept(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('A'),
            rules: [new Rule(
                'A',
                RuleVerb::MayOnlyDependOn,
                new InlinePattern('Symfony\**'),
                objectExcept: [new InlinePattern('Symfony\Legacy\**')],
                objectElementKinds: [ElementKind::Interface],
            )],
        );
        $resolution = $this->resolution([
            'A' => [$this->element('App\A\Foo')],
        ]);
        // An interface under the excepted sub-namespace is still rejected: except narrows
        // the pattern match itself, independent of the kind filter.
        $dependency = new Dependency('App\A\Foo', 'Symfony\Legacy\BarInterface', DependencyKind::Instantiate, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
    }

    public function testElementKindFilterNeverMatchesATargetWithNoResolvedElement(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('A'),
            rules: [new Rule(
                'A',
                RuleVerb::MayOnlyDependOn,
                new InlinePattern('Vendor\**'),
                objectElementKinds: [ElementKind::Interface],
            )],
        );
        $resolution = $this->resolution([
            'A' => [$this->element('App\A\Foo')],
        ]);
        // Vendor\SomeClass was never discovered by the Loader/Parser — no Element exists
        // for it at all, so the kind filter can never be satisfied, same as "no Element,
        // no layer, no match" everywhere else in this engine.
        $dependency = new Dependency('App\A\Foo', 'Vendor\SomeClass', DependencyKind::Instantiate, 'Foo.php', 1);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
    }

    public function testSubtypesOfFlagsATransitiveSubtype(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, new TypeHierarchyTarget('App\Legacy\BadBase'))],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
        ]);
        // Leaf -> Mid -> BadBase: BadBase isn't Leaf's direct supertype, only its
        // grandparent, so this only violates if the ancestor walk is actually
        // transitive, not just a one-hop lookup. Leaf itself has no declared layer at
        // all (only Domain is declared), proving a `subtypes of` object still matches
        // against unresolved-layer code, same as InlinePattern/ReservedTarget already do.
        $dependencies = [
            new Dependency('App\Legacy\Mid', 'App\Legacy\BadBase', DependencyKind::Extends, 'Mid.php', 1),
            new Dependency('App\Legacy\Leaf', 'App\Legacy\Mid', DependencyKind::Extends, 'Leaf.php', 1),
            new Dependency('App\Domain\Foo', 'App\Legacy\Leaf', DependencyKind::Instantiate, 'Foo.php', 5),
        ];

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, $dependencies);

        self::assertCount(1, $violations);
        self::assertSame('App\Legacy\Leaf', $violations[0]->toElement);
    }

    public function testSubtypesOfReflexivelyMatchesTheBaseTypeItself(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, new TypeHierarchyTarget('App\Legacy\BadBase'))],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
        ]);
        $dependency = new Dependency('App\Domain\Foo', 'App\Legacy\BadBase', DependencyKind::Instantiate, 'Foo.php', 5);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(1, $violations);
    }

    public function testSubtypesOfProducesNoViolationForAnUnrelatedClass(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain'),
            rules: [new Rule('Domain', RuleVerb::MustNotDependOn, new TypeHierarchyTarget('App\Legacy\BadBase'))],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
        ]);
        $dependency = new Dependency('App\Domain\Foo', 'App\Legacy\Unrelated', DependencyKind::Instantiate, 'Foo.php', 5);

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, [$dependency]);

        self::assertCount(0, $violations);
    }

    public function testSubtypesOfExceptCarvesOutASpecificSubtype(): void
    {
        $ruleset = new Ruleset(
            layers: $this->layers('Domain'),
            rules: [new Rule(
                'Domain',
                RuleVerb::MayOnlyDependOn,
                new TypeHierarchyTarget('App\Exception\Base'),
                DependencyKind::Throw,
                objectExcept: [new TypeHierarchyTarget('App\Exception\Ignored')],
            )],
        );
        $resolution = $this->resolution([
            'Domain' => [$this->element('App\Domain\Foo')],
        ]);
        $dependencies = [
            new Dependency('App\Exception\Ignored', 'App\Exception\Base', DependencyKind::Extends, 'Ignored.php', 1),
            new Dependency('App\Exception\Allowed', 'App\Exception\Base', DependencyKind::Extends, 'Allowed.php', 1),
            new Dependency('App\Domain\Foo', 'App\Exception\Ignored', DependencyKind::Throw, 'Foo.php', 5),
            new Dependency('App\Domain\Foo', 'App\Exception\Allowed', DependencyKind::Throw, 'Foo.php', 6),
        ];

        $violations = (new RuleEngine())->evaluate($ruleset, $resolution, $dependencies);

        self::assertCount(1, $violations);
        self::assertSame('App\Exception\Ignored', $violations[0]->toElement);
    }

    /**
     * @param array<string, Element[]> $matches
     */
    private function resolution(array $matches): LayerResolution
    {
        return new LayerResolution($matches, []);
    }

    private function element(string $fqcn, ElementKind $kind = ElementKind::ClassLike): Element
    {
        return new Element($fqcn, $kind, 'test.php', 1);
    }

    /**
     * @return Layer[]
     */
    private function layers(string ...$names): array
    {
        return array_map(static fn (string $name): Layer => new Layer($name, []), $names);
    }
}
