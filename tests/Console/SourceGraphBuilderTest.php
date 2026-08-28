<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Console;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Cache\Cache;
use Spandrel\Spandrel\Console\SourceGraphBuilder;
use Spandrel\Spandrel\Loader\Loader;
use Spandrel\Spandrel\Parser\Parser;

final class SourceGraphBuilderTest extends TestCase
{
    private string $sourceDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->sourceDir = sys_get_temp_dir().'/spandrel-builder-src-'.uniqid();
        $this->cacheDir = sys_get_temp_dir().'/spandrel-builder-cache-'.uniqid();
        mkdir($this->sourceDir);
    }

    protected function tearDown(): void
    {
        foreach ([$this->sourceDir, $this->cacheDir] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($dir);
        }
    }

    public function testBuildWithoutCacheMatchesFixtureContent(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp/src';

        // These fixture classes don't extend/implement/use anything, so
        // $dependencies is legitimately empty — elements are what this asserts.
        [$elements] = $this->builder()->build([$fixtures]);

        self::assertNotEmpty($elements);

        $fqcns = array_map(static fn ($element): string => $element->fqcn, $elements);
        self::assertContains('App\Domain\User', $fqcns);
        self::assertContains('App\Infrastructure\PdoUserRepository', $fqcns);
    }

    public function testExcludeParameterIsPassedThroughToTheLoader(): void
    {
        mkdir($this->sourceDir.'/Generated');
        file_put_contents($this->sourceDir.'/Foo.php', "<?php\n\nnamespace App;\n\nclass Foo\n{\n}\n");
        file_put_contents($this->sourceDir.'/Generated/Bar.php', "<?php\n\nnamespace App\Generated;\n\nclass Bar\n{\n}\n");

        [$elements] = $this->builder()->build([$this->sourceDir], null, ['Generated']);

        $fqcns = array_map(static fn ($e): string => $e->fqcn, $elements);
        self::assertContains('App\Foo', $fqcns);
        self::assertNotContains('App\Generated\Bar', $fqcns);

        unlink($this->sourceDir.'/Generated/Bar.php');
        rmdir($this->sourceDir.'/Generated');
    }

    public function testBuildWithCacheReturnsValueIdenticalResultsOnSecondRun(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp/src';

        [$firstElements, $firstDependencies] = $this->builder()->build([$fixtures], new Cache($this->cacheDir));
        [$secondElements, $secondDependencies] = $this->builder()->build([$fixtures], new Cache($this->cacheDir));

        self::assertEquals($firstElements, $secondElements);
        self::assertEquals($firstDependencies, $secondDependencies);
    }

    public function testChangedFileContentInvalidatesItsCacheEntry(): void
    {
        file_put_contents($this->sourceDir.'/Foo.php', <<<'PHP'
            <?php

            namespace App;

            class Foo
            {
            }
            PHP);

        $cache = new Cache($this->cacheDir);
        [$firstElements] = $this->builder()->build([$this->sourceDir], $cache);
        self::assertSame(['App\Foo'], array_map(static fn ($e): string => $e->fqcn, $firstElements));

        file_put_contents($this->sourceDir.'/Foo.php', <<<'PHP'
            <?php

            namespace App;

            class Bar
            {
            }
            PHP);

        [$secondElements] = $this->builder()->build([$this->sourceDir], $cache);
        self::assertSame(['App\Bar'], array_map(static fn ($e): string => $e->fqcn, $secondElements));
    }

    public function testRemovingAFilePurgesItsCacheEntryOnRebuild(): void
    {
        $originalContents = <<<'PHP'
            <?php

            namespace App;

            class Foo
            {
            }
            PHP;
        file_put_contents($this->sourceDir.'/Foo.php', $originalContents);

        $cache = new Cache($this->cacheDir);
        $this->builder()->build([$this->sourceDir], $cache);

        $originalHash = hash('xxh3', $originalContents);
        self::assertNotNull($cache->get('Foo.php', $originalHash));

        unlink($this->sourceDir.'/Foo.php');
        $this->builder()->build([$this->sourceDir], $cache);

        $fresh = new Cache($this->cacheDir);
        self::assertNull($fresh->get('Foo.php', $originalHash));
    }

    public function testFileWithASyntaxErrorIsSkippedNotThrown(): void
    {
        $fixtures = __DIR__.'/../Fixtures/BrokenApp/src';

        [$elements, , $parseErrors] = $this->builder()->build([$fixtures]);

        $fqcns = array_map(static fn ($e): string => $e->fqcn, $elements);
        self::assertContains('App\Good', $fqcns);

        self::assertCount(1, $parseErrors);
        self::assertSame('Broken.php', $parseErrors[0]->file);
        self::assertNotSame('', $parseErrors[0]->message);
    }

    private function builder(): SourceGraphBuilder
    {
        return new SourceGraphBuilder(new Loader(), new Parser());
    }
}
