<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Parser;

final class ParseError
{
    public function __construct(
        public readonly string $file,
        public readonly string $message,
    ) {
    }
}
