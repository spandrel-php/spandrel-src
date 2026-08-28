<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Parser;

use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\Element;

final class ParseResult
{
    /**
     * @param Element[] $elements
     * @param Dependency[] $dependencies
     */
    public function __construct(
        public readonly array $elements,
        public readonly array $dependencies,
    ) {
    }
}
