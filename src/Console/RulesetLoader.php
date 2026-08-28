<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Console;

use Spandrel\Spandrel\Ruleset\Ruleset;
use Spandrel\Spandrel\Ruleset\RulesetParseException;
use Spandrel\Spandrel\Ruleset\RulesetParser;

/**
 * Resolves one or more `--ruleset` paths into a single, merged `Ruleset`,
 * shared by every command that reads one. File-not-found and unreadable
 * are folded into `RulesetParseException` too, so callers need exactly
 * one catch block for every way this can fail.
 */
final class RulesetLoader
{
    public function __construct(private readonly RulesetParser $parser)
    {
    }

    /**
     * @param string[] $paths
     * @param \Spandrel\Spandrel\Graph\Element[] $elements
     * @throws RulesetParseException
     */
    public function load(array $paths, array $elements = []): Ruleset
    {
        $files = [];

        foreach ($paths as $path) {
            if (!is_file($path)) {
                throw RulesetParseException::fileNotFound($path);
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw RulesetParseException::unreadable($path);
            }

            $files[] = [$path, $contents];
        }

        return $this->parser->parseAll($files, $elements);
    }
}
