<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Cache;

use FilesystemIterator;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;

/**
 * Skips re-parsing a file whose content hasn't changed since the last run.
 * Backed by a single serialized file per directory, loaded lazily and
 * written once per run via `reconcileAndSave()` rather than per entry. A
 * missing or corrupt cache file is always treated as an empty cache — a
 * performance optimization, never a correctness dependency.
 */
final class Cache
{
    public const SCHEMA_VERSION = 1;

    private const FILE_NAME = 'cache.data';

    /** @var array<string, FileAnalysis>|null */
    private ?array $entries = null;

    public function __construct(private readonly string $directory)
    {
    }

    public function get(string $relativePath, string $contentHash): ?FileAnalysis
    {
        $entry = $this->load()[$relativePath] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry->contentHash !== $contentHash || $entry->schemaVersion !== self::SCHEMA_VERSION) {
            return null;
        }

        return $entry;
    }

    public function put(FileAnalysis $entry): void
    {
        $this->load();
        $this->entries[$entry->relativePath] = $entry;
    }

    /**
     * @param string[] $seenRelativePaths
     */
    public function reconcileAndSave(array $seenRelativePaths): void
    {
        $entries = $this->load();
        $seen = array_flip($seenRelativePaths);
        $entries = array_intersect_key($entries, $seen);
        $this->entries = $entries;

        if (!is_dir($this->directory)) {
            mkdir($this->directory, recursive: true);
        }

        file_put_contents($this->path(), serialize($entries));
    }

    public function clear(): void
    {
        $this->entries = [];

        if (is_file($this->path())) {
            unlink($this->path());
        }

        if (is_dir($this->directory) && (new FilesystemIterator($this->directory))->valid() === false) {
            rmdir($this->directory);
        }
    }

    /**
     * @return array<string, FileAnalysis>
     */
    private function load(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $contents = is_file($this->path()) ? file_get_contents($this->path()) : false;

        if ($contents === false) {
            return $this->entries = [];
        }

        $data = @unserialize($contents, [
            'allowed_classes' => [
                FileAnalysis::class,
                Element::class,
                ElementKind::class,
                Dependency::class,
                DependencyKind::class,
            ],
        ]);

        if (!is_array($data)) {
            return $this->entries = [];
        }

        /** @var array<string, FileAnalysis> $entries */
        $entries = array_filter($data, static fn (mixed $value): bool => $value instanceof FileAnalysis);

        return $this->entries = $entries;
    }

    private function path(): string
    {
        return rtrim($this->directory, '/').'/'.self::FILE_NAME;
    }
}
