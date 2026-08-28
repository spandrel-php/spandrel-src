<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Cache\Cache;
use Spandrel\Spandrel\Cache\FileAnalysis;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;

final class CacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/spandrel-cache-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->directory);
        }
    }

    public function testMissOnEmptyCache(): void
    {
        $cache = new Cache($this->directory);

        self::assertNull($cache->get('Foo.php', 'hash'));
    }

    public function testPutThenGetRoundTrips(): void
    {
        $cache = new Cache($this->directory);
        $cache->put($this->analysis('Foo.php', 'hash-a'));

        $entry = $cache->get('Foo.php', 'hash-a');

        self::assertNotNull($entry);
        self::assertSame('Foo.php', $entry->relativePath);
        self::assertSame('App\Foo', $entry->elements[0]->fqcn);
    }

    public function testMissOnContentHashMismatch(): void
    {
        $cache = new Cache($this->directory);
        $cache->put($this->analysis('Foo.php', 'hash-a'));

        self::assertNull($cache->get('Foo.php', 'hash-b'));
    }

    public function testMissOnSchemaVersionMismatch(): void
    {
        $cache = new Cache($this->directory);
        $cache->put(new FileAnalysis('Foo.php', 'hash-a', Cache::SCHEMA_VERSION - 1, [], []));

        self::assertNull($cache->get('Foo.php', 'hash-a'));
    }

    public function testReconcileAndSavePurgesEntriesNotSeen(): void
    {
        $cache = new Cache($this->directory);
        $cache->put($this->analysis('Foo.php', 'hash-a'));
        $cache->put($this->analysis('Bar.php', 'hash-b'));
        $cache->reconcileAndSave(['Foo.php']);

        self::assertNotNull($cache->get('Foo.php', 'hash-a'));
        self::assertNull($cache->get('Bar.php', 'hash-b'));
    }

    public function testDataPersistsAcrossSeparateCacheInstances(): void
    {
        $first = new Cache($this->directory);
        $first->put($this->analysis('Foo.php', 'hash-a'));
        $first->reconcileAndSave(['Foo.php']);

        $second = new Cache($this->directory);

        self::assertNotNull($second->get('Foo.php', 'hash-a'));
    }

    public function testCorruptCacheFileIsTreatedAsEmpty(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory.'/cache.data', 'not a valid serialized payload');

        $cache = new Cache($this->directory);

        self::assertNull($cache->get('Foo.php', 'hash-a'));
    }

    public function testClearRemovesTheStoredFileAndDirectory(): void
    {
        $cache = new Cache($this->directory);
        $cache->put($this->analysis('Foo.php', 'hash-a'));
        $cache->reconcileAndSave(['Foo.php']);

        $cache->clear();

        self::assertFalse(is_file($this->directory.'/cache.data'));
        self::assertFalse(is_dir($this->directory));
        self::assertNull((new Cache($this->directory))->get('Foo.php', 'hash-a'));
    }

    private function analysis(string $relativePath, string $contentHash): FileAnalysis
    {
        return new FileAnalysis(
            $relativePath,
            $contentHash,
            Cache::SCHEMA_VERSION,
            [new Element('App\Foo', ElementKind::ClassLike, $relativePath, 1)],
            [new Dependency('App\Foo', 'App\Bar', DependencyKind::Extends, $relativePath, 1)],
        );
    }
}
