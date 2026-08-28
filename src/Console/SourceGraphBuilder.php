<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Console;

use PhpParser\Error;
use Spandrel\Spandrel\Cache\Cache;
use Spandrel\Spandrel\Cache\FileAnalysis;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Loader\Loader;
use Spandrel\Spandrel\Parser\ParseError;
use Spandrel\Spandrel\Parser\Parser;

/**
 * Shared "load + parse every source file under these paths" loop every
 * command needs before it can touch a Code Graph. Optionally backed by a
 * `Cache`: a cache hit skips `Parser::parse()` entirely and reuses the
 * stored `Element`/`Dependency` lists as-is.
 *
 * A file with a PHP syntax error is skipped, not fatal, and collected into
 * the returned `ParseError[]` so a caller that cares (`analyse --strict`)
 * can escalate; every other caller can ignore the third return element.
 */
final class SourceGraphBuilder
{
    public function __construct(
        private readonly Loader $loader,
        private readonly Parser $parser,
    ) {
    }

    /**
     * @param string[] $sourcePaths
     * @param string[] $exclude
     * @return array{0: Element[], 1: Dependency[], 2: ParseError[]}
     */
    public function build(array $sourcePaths, ?Cache $cache = null, array $exclude = []): array
    {
        $elements = [];
        $dependencies = [];
        $parseErrors = [];
        $seenRelativePaths = [];

        foreach ($sourcePaths as $sourcePath) {
            foreach ($this->loader->find($sourcePath, $exclude) as $sourceFile) {
                $fileContents = file_get_contents($sourceFile->absolutePath);

                if ($fileContents === false) {
                    continue;
                }

                $seenRelativePaths[] = $sourceFile->relativePath;

                $contentHash = null;
                $cached = null;

                if ($cache !== null) {
                    $contentHash = hash('xxh3', $fileContents);
                    $cached = $cache->get($sourceFile->relativePath, $contentHash);
                }

                if ($cached !== null) {
                    array_push($elements, ...$cached->elements);
                    array_push($dependencies, ...$cached->dependencies);

                    continue;
                }

                try {
                    $result = $this->parser->parse($sourceFile->relativePath, $fileContents);
                } catch (\PhpParser\Error $e) {
                    $parseErrors[] = new ParseError($sourceFile->relativePath, $e->getMessage());

                    continue;
                }

                foreach ($result->elements as $element) {
                    $elements[] = $element;
                }

                foreach ($result->dependencies as $dependency) {
                    $dependencies[] = $dependency;
                }

                if ($cache !== null) {
                    $cache->put(new FileAnalysis(
                        $sourceFile->relativePath,
                        $contentHash,
                        Cache::SCHEMA_VERSION,
                        $result->elements,
                        $result->dependencies,
                    ));
                }
            }
        }

        $cache?->reconcileAndSave($seenRelativePaths);

        return [$elements, $dependencies, $parseErrors];
    }
}
