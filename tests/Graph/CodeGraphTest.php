<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\CodeGraph;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;

final class CodeGraphTest extends TestCase
{
    public function testFromElementsIndexesByFqcn(): void
    {
        $foo = new Element('App\Foo', ElementKind::ClassLike, 'Foo.php', 1);
        $bar = new Element('App\Bar', ElementKind::ClassLike, 'Bar.php', 1);
        $dependency = new Dependency('App\Foo', 'App\Bar', DependencyKind::Extends, 'Foo.php', 3);

        $graph = CodeGraph::fromElements([$foo, $bar], [$dependency]);

        self::assertSame($foo, $graph->elements['App\Foo']);
        self::assertSame($bar, $graph->elements['App\Bar']);
        self::assertSame([$dependency], $graph->dependencies);
    }
}
