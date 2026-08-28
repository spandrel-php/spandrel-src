<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Loader;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class Loader
{
    // Always excluded on top of `$exclude`, relative to `$root`, so a normal run
    // never accidentally parses every dependency's source. Reach into a specific
    // vendor package by pointing a separate `$root` at it directly.
    private const DEFAULT_EXCLUDES = ['vendor'];

    /**
     * @param string[] $exclude glob patterns (`?`/`*`/`[...]` via `fnmatch()`)
     *                          or plain paths, matched against each file's
     *                          path relative to `$root` — a plain path like
     *                          `src/Generated` excludes that path and
     *                          everything under it, without needing wildcards
     * @return iterable<SourceFile>
     */
    public function find(string $root, array $exclude = []): iterable
    {
        $root = rtrim($root, '/');
        $excludePatterns = [...self::DEFAULT_EXCLUDES, ...$exclude];

        if (is_file($root)) {
            yield new SourceFile($root, basename($root));

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = ltrim(substr($absolutePath, strlen($root)), '/');

            if (self::isExcluded($relativePath, $excludePatterns)) {
                continue;
            }

            yield new SourceFile($absolutePath, $relativePath);
        }
    }

    /**
     * @param string[] $patterns
     */
    private static function isExcluded(string $relativePath, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern, '/');

            if ($relativePath === $pattern || str_starts_with($relativePath, $pattern.'/') || fnmatch($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }
}
