<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Config;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Config\ConfigParseException;

final class ConfigLoaderTest extends TestCase
{
    public function testReturnsNullWhenDefaultFileDoesNotExist(): void
    {
        $originalCwd = getcwd();
        self::assertIsString($originalCwd);

        $emptyDir = sys_get_temp_dir().'/spandrel-config-test-'.uniqid();
        mkdir($emptyDir);

        try {
            chdir($emptyDir);
            self::assertNull((new ConfigLoader())->load(null));
        } finally {
            chdir($originalCwd);
            rmdir($emptyDir);
        }
    }

    public function testThrowsWhenExplicitFileDoesNotExist(): void
    {
        $this->expectException(ConfigParseException::class);
        $this->expectExceptionMessage('/does/not/exist.yaml');

        (new ConfigLoader())->load('/does/not/exist.yaml');
    }

    public function testThrowsOnMalformedYaml(): void
    {
        $this->expectException(ConfigParseException::class);

        (new ConfigLoader())->load(__DIR__.'/../Fixtures/DemoApp/spandrel-malformed.yaml');
    }

    public function testThrowsWhenTopLevelIsNotAMapping(): void
    {
        $this->expectException(ConfigParseException::class);

        (new ConfigLoader())->load(__DIR__.'/../Fixtures/DemoApp/spandrel-scalar.yaml');
    }

    public function testParsesValidConfigIgnoringUnknownKeys(): void
    {
        $config = (new ConfigLoader())->load(__DIR__.'/../Fixtures/DemoApp/spandrel.yaml');

        self::assertNotNull($config);
        self::assertSame(['src'], $config->sourcePaths);
        self::assertSame('architecture.md', $config->ruleset);
        self::assertSame('.spandrel-cache', $config->cacheDirectory);
        self::assertSame('spandrel-baseline.json', $config->baselinePath);
    }

    public function testMissingSourceOrRulesetKeysDefaultToEmpty(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'spandrel-config-');
        self::assertIsString($path);
        file_put_contents($path, "ruleset: custom.md\n");

        try {
            $config = (new ConfigLoader())->load($path);

            self::assertNotNull($config);
            self::assertSame([], $config->sourcePaths);
            self::assertSame([], $config->excludePaths);
            self::assertSame('custom.md', $config->ruleset);
            self::assertNull($config->cacheDirectory);
            self::assertNull($config->baselinePath);
        } finally {
            unlink($path);
        }
    }

    public function testReadsBaselinePath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'spandrel-config-');
        self::assertIsString($path);
        file_put_contents($path, "baseline: spandrel-baseline.json\n");

        try {
            $config = (new ConfigLoader())->load($path);

            self::assertNotNull($config);
            self::assertSame('spandrel-baseline.json', $config->baselinePath);
        } finally {
            unlink($path);
        }
    }

    public function testReadsSourceExclude(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'spandrel-config-');
        self::assertIsString($path);
        file_put_contents($path, "source:\n    paths:\n        - src\n    exclude:\n        - src/Generated\n");

        try {
            $config = (new ConfigLoader())->load($path);

            self::assertNotNull($config);
            self::assertSame(['src'], $config->sourcePaths);
            self::assertSame(['src/Generated'], $config->excludePaths);
        } finally {
            unlink($path);
        }
    }

    public function testReadsCacheDirectory(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'spandrel-config-');
        self::assertIsString($path);
        file_put_contents($path, "cache:\n    directory: .spandrel-cache\n");

        try {
            $config = (new ConfigLoader())->load($path);

            self::assertNotNull($config);
            self::assertSame('.spandrel-cache', $config->cacheDirectory);
        } finally {
            unlink($path);
        }
    }
}
