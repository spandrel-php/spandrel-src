<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\RuleEngine;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\RuleEngine\TypeHierarchy;

final class TypeHierarchyTest extends TestCase
{
    public function testDirectExtendsIsASubtype(): void
    {
        $hierarchy = new TypeHierarchy([
            new Dependency('App\Child', 'App\Base', DependencyKind::Extends, 'Child.php', 1),
        ]);

        self::assertTrue($hierarchy->isSubtypeOf('App\Child', 'App\Base'));
    }

    public function testTransitiveExtendsIsASubtype(): void
    {
        $hierarchy = new TypeHierarchy([
            new Dependency('App\Grandchild', 'App\Child', DependencyKind::Extends, 'Grandchild.php', 1),
            new Dependency('App\Child', 'App\Base', DependencyKind::Extends, 'Child.php', 1),
        ]);

        self::assertTrue($hierarchy->isSubtypeOf('App\Grandchild', 'App\Base'));
    }

    public function testImplementsCountsTheSameAsExtends(): void
    {
        $hierarchy = new TypeHierarchy([
            new Dependency('App\Impl', 'App\SomeInterface', DependencyKind::Implements, 'Impl.php', 1),
        ]);

        self::assertTrue($hierarchy->isSubtypeOf('App\Impl', 'App\SomeInterface'));
    }

    public function testATypeIsItsOwnSubtype(): void
    {
        $hierarchy = new TypeHierarchy([]);

        self::assertTrue($hierarchy->isSubtypeOf('App\Base', 'App\Base'));
    }

    public function testUnrelatedClassesDoNotMatch(): void
    {
        $hierarchy = new TypeHierarchy([
            new Dependency('App\Child', 'App\Base', DependencyKind::Extends, 'Child.php', 1),
        ]);

        self::assertFalse($hierarchy->isSubtypeOf('App\Unrelated', 'App\Base'));
    }

    public function testAClassWithNoRecordedSupertypeHasNoAncestors(): void
    {
        $hierarchy = new TypeHierarchy([]);

        self::assertFalse($hierarchy->isSubtypeOf('App\Lonely', 'App\Base'));
    }

    public function testUseTraitDoesNotCountAsAnIsARelationship(): void
    {
        $hierarchy = new TypeHierarchy([
            new Dependency('App\Foo', 'App\SomeTrait', DependencyKind::UseTrait, 'Foo.php', 1),
        ]);

        self::assertFalse($hierarchy->isSubtypeOf('App\Foo', 'App\SomeTrait'));
    }

    public function testDiamondShapedHierarchyDoesNotLoopOrDoubleCount(): void
    {
        $hierarchy = new TypeHierarchy([
            new Dependency('App\Bottom', 'App\Left', DependencyKind::Extends, 'Bottom.php', 1),
            new Dependency('App\Bottom', 'App\Right', DependencyKind::Implements, 'Bottom.php', 1),
            new Dependency('App\Left', 'App\Top', DependencyKind::Extends, 'Left.php', 1),
            new Dependency('App\Right', 'App\Top', DependencyKind::Implements, 'Right.php', 1),
        ]);

        self::assertTrue($hierarchy->isSubtypeOf('App\Bottom', 'App\Top'));
        self::assertTrue($hierarchy->isSubtypeOf('App\Bottom', 'App\Left'));
        self::assertTrue($hierarchy->isSubtypeOf('App\Bottom', 'App\Right'));
    }

    public function testCircularExtendsDoesNotHang(): void
    {
        $hierarchy = new TypeHierarchy([
            new Dependency('App\A', 'App\B', DependencyKind::Extends, 'A.php', 1),
            new Dependency('App\B', 'App\A', DependencyKind::Extends, 'B.php', 1),
        ]);

        self::assertTrue($hierarchy->isSubtypeOf('App\A', 'App\B'));
        self::assertTrue($hierarchy->isSubtypeOf('App\B', 'App\A'));
    }
}
