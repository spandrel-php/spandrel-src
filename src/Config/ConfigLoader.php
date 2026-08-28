<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads `spandrel.yaml`. `source.paths`, `source.exclude`, `ruleset`,
 * `cache.directory`, and `baseline` are all read. No upward
 * directory-tree discovery: the default path is resolved relative to
 * the current working directory only, same as every other default path
 * in this codebase.
 */
final class ConfigLoader
{
    private const DEFAULT_PATH = 'spandrel.yaml';

    public function load(?string $explicitPath): ?Config
    {
        $path = $explicitPath ?? self::DEFAULT_PATH;

        if (!is_file($path)) {
            if ($explicitPath !== null) {
                throw ConfigParseException::fileNotFound($path);
            }

            return null;
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw ConfigParseException::malformed($path, $e->getMessage());
        }

        if (!is_array($data)) {
            throw ConfigParseException::malformed($path, 'expected a YAML mapping at the top level');
        }

        return new Config(
            sourcePaths: $this->sourceStringList($data, 'paths'),
            excludePaths: $this->sourceStringList($data, 'exclude'),
            ruleset: $this->ruleset($data),
            cacheDirectory: $this->cacheDirectory($data),
            baselinePath: $this->baselinePath($data),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return string[]
     */
    private function sourceStringList(array $data, string $key): array
    {
        $source = $data['source'] ?? null;

        if (!is_array($source)) {
            return [];
        }

        $values = $source[$key] ?? null;

        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value)));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function ruleset(array $data): ?string
    {
        $ruleset = $data['ruleset'] ?? null;

        return is_string($ruleset) ? $ruleset : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function cacheDirectory(array $data): ?string
    {
        $cache = $data['cache'] ?? null;

        if (!is_array($cache)) {
            return null;
        }

        $directory = $cache['directory'] ?? null;

        return is_string($directory) ? $directory : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function baselinePath(array $data): ?string
    {
        $baseline = $data['baseline'] ?? null;

        return is_string($baseline) ? $baseline : null;
    }
}
