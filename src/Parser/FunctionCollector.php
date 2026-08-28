<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Parser;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;

/**
 * Collects namespaced free-function declarations as `Element`s (kind
 * `function`), so a `Call` edge to one has something to resolve a layer
 * against.
 *
 * PHP keeps separate symbol tables for classes and functions, so
 * `App\Foo\helper` could name a class *and* a function simultaneously.
 * `Element`/`Dependency`/`LayerResolution` identify things by bare FQCN
 * only, so the two would collide as if they were the same element — a
 * known gap, not fixed here since it's rare in practice.
 */
final class FunctionCollector extends NodeVisitorAbstract
{
    /** @var Element[] */
    private array $elements = [];

    public function __construct(
        private readonly string $file,
        private readonly ColumnCalculator $columns,
    ) {
    }

    public function enterNode(Node $node): null
    {
        if (!$node instanceof Node\Stmt\Function_) {
            return null;
        }

        if (!$node->namespacedName instanceof Node\Name) {
            return null;
        }

        $this->elements[] = new Element(
            fqcn: $node->namespacedName->toString(),
            kind: ElementKind::Function,
            file: $this->file,
            line: $node->getLine(),
            column: $this->columns->columnAt($node->getStartFilePos()),
        );

        return null;
    }

    /** @return Element[] */
    public function elements(): array
    {
        return $this->elements;
    }
}
