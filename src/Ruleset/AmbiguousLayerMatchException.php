<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

final class AmbiguousLayerMatchException extends \RuntimeException
{
    /**
     * @param string[] $layerNames
     */
    public static function forElement(string $fqcn, array $layerNames): self
    {
        return new self(sprintf(
            '"%s" matches more than one layer: %s',
            $fqcn,
            implode(', ', $layerNames),
        ));
    }
}
