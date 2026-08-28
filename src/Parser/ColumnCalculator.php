<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Parser;

/**
 * Converts a 0-based file offset (`Node::getStartFilePos()`/
 * `getEndFilePos()` — attached to every node by default in
 * `nikic/php-parser` ^5.8, no lexer reconfiguration needed) into a
 * 1-based column. Reproduces the exact algorithm
 * `PhpParser\Error::toColumn()` already uses internally, one instance
 * per parsed file's contents.
 */
final class ColumnCalculator
{
    public function __construct(private readonly string $contents)
    {
    }

    public function columnAt(int $filePos): int
    {
        if ($filePos < 0) {
            return 1;
        }

        $lineStartPos = strrpos($this->contents, "\n", $filePos - strlen($this->contents));

        return $filePos - ($lineStartPos === false ? -1 : $lineStartPos);
    }
}
