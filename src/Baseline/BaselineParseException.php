<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Baseline;

final class BaselineParseException extends \RuntimeException
{
    public static function unreadable(string $path): self
    {
        return new self(sprintf('Could not read baseline file: %s', $path));
    }

    public static function malformed(string $path): self
    {
        return new self(sprintf(
            'Could not parse baseline file %s: expected {"violations": [{"from": ..., "to": ..., "kind": ...}, ...]}',
            $path,
        ));
    }
}
