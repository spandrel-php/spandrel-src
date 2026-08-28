<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Graph;

final class CodeGraph
{
    /**
     * @param array<string, Element> $elements keyed by FQCN
     * @param Dependency[] $dependencies
     */
    public function __construct(
        public readonly array $elements,
        public readonly array $dependencies,
    ) {
    }

    /**
     * @param Element[] $elements
     * @param Dependency[] $dependencies
     */
    public static function fromElements(array $elements, array $dependencies): self
    {
        $byFqcn = [];

        foreach ($elements as $element) {
            $byFqcn[$element->fqcn] = $element;
        }

        return new self($byFqcn, $dependencies);
    }
}
