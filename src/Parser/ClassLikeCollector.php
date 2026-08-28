<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Parser;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;

final class ClassLikeCollector extends NodeVisitorAbstract
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
        $kind = match (true) {
            $node instanceof Node\Stmt\Class_ => ElementKind::ClassLike,
            $node instanceof Node\Stmt\Interface_ => ElementKind::Interface,
            $node instanceof Node\Stmt\Trait_ => ElementKind::Trait,
            $node instanceof Node\Stmt\Enum_ => ElementKind::Enum,
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        // Anonymous classes have no name and no namespacedName.
        if (!$node->namespacedName instanceof Node\Name) {
            return null;
        }

        $this->elements[] = new Element(
            fqcn: $node->namespacedName->toString(),
            kind: $kind,
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
