<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Config;

final class ConfigParseException extends \RuntimeException
{
    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('Config file not found: %s', $path));
    }

    public static function malformed(string $path, string $reason): self
    {
        return new self(sprintf('Could not parse config file %s: %s', $path, $reason));
    }
}
