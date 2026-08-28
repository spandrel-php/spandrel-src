<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Loader\Loader;

final class LoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/spandrel-loader-test-'.uniqid();
        mkdir($this->root.'/vendor', recursive: true);
        mkdir($this->root.'/src/Generated', recursive: true);
        mkdir($this->root.'/VendorAdapter', recursive: true);

        file_put_contents($this->root.'/Foo.php', '<?php');
        file_put_contents($this->root.'/vendor/Bar.php', '<?php');
        file_put_contents($this->root.'/src/Generated/Baz.php', '<?php');
        file_put_contents($this->root.'/src/GeneratedFoo.php', '<?php');
        file_put_contents($this->root.'/VendorAdapter/Thing.php', '<?php');
        file_put_contents($this->root.'/build.min.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExcludesVendorDirectoryByDefault(): void
    {
        $paths = $this->relativePaths((new Loader())->find($this->root));

        self::assertNotContains('vendor/Bar.php', $paths);
        self::assertContains('Foo.php', $paths);
    }

    public function testDirectoryThatOnlyResemblesVendorIsNotExcluded(): void
    {
        $paths = $this->relativePaths((new Loader())->find($this->root));

        self::assertContains('VendorAdapter/Thing.php', $paths);
    }

    public function testCustomExcludeRemovesADirectoryAndEverythingUnderIt(): void
    {
        $paths = $this->relativePaths((new Loader())->find($this->root, ['src/Generated']));

        self::assertNotContains('src/Generated/Baz.php', $paths);
    }

    public function testCustomExcludeDoesNotMatchAsASubstringPrefix(): void
    {
        $paths = $this->relativePaths((new Loader())->find($this->root, ['src/Generated']));

        self::assertContains('src/GeneratedFoo.php', $paths);
    }

    public function testCustomExcludeSupportsGlobPatterns(): void
    {
        $paths = $this->relativePaths((new Loader())->find($this->root, ['*.min.php']));

        self::assertNotContains('build.min.php', $paths);
        self::assertContains('Foo.php', $paths);
    }

    /**
     * @param iterable<\Spandrel\Spandrel\Loader\SourceFile> $files
     * @return string[]
     */
    private function relativePaths(iterable $files): array
    {
        $paths = [];

        foreach ($files as $file) {
            $paths[] = $file->relativePath;
        }

        return $paths;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
