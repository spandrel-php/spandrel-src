<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Console;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Cache\Cache;
use Spandrel\Spandrel\Cache\FileAnalysis;
use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Console\ClearCacheCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ClearCacheCommandTest extends TestCase
{
    public function testClearsAnExistingCacheDirectory(): void
    {
        $cacheDir = sys_get_temp_dir().'/spandrel-clear-cache-'.uniqid();
        $cache = new Cache($cacheDir);
        $cache->put(new FileAnalysis('Foo.php', 'hash', Cache::SCHEMA_VERSION, [], []));
        $cache->reconcileAndSave(['Foo.php']);

        self::assertFileExists($cacheDir.'/cache.data');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--cache-dir' => $cacheDir]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileDoesNotExist($cacheDir.'/cache.data');
        self::assertStringContainsString('Cleared cache', $tester->getDisplay());
    }

    public function testFailsWhenNoCacheDirectoryIsResolvable(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'spandrel-yaml-');
        self::assertIsString($configPath);
        file_put_contents($configPath, "ruleset: architecture.md\n");

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute(['--config' => $configPath]);

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('No cache directory given', $tester->getDisplay());
        } finally {
            unlink($configPath);
        }
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ClearCacheCommand(new ConfigLoader()));

        return new CommandTester($application->find('cache:clear'));
    }
}
