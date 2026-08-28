<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Console;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Console\InitCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InitCommandTest extends TestCase
{
    private string $originalCwd;
    private string $dir;

    protected function setUp(): void
    {
        $cwd = getcwd();
        self::assertIsString($cwd);
        $this->originalCwd = $cwd;

        $this->dir = sys_get_temp_dir().'/spandrel-init-test-'.uniqid();
        mkdir($this->dir);
        chdir($this->dir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);

        foreach (['architecture.md', 'spandrel.yaml'] as $file) {
            @unlink($this->dir.'/'.$file);
        }

        rmdir($this->dir);
    }

    public function testWritesBothFilesWithUsableContent(): void
    {
        $exitCode = $this->tester()->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists('architecture.md');
        self::assertFileExists('spandrel.yaml');

        $architecture = (string) file_get_contents('architecture.md');
        self::assertStringContainsString('## Layers', $architecture);
        self::assertStringContainsString('## Rules', $architecture);

        $config = (string) file_get_contents('spandrel.yaml');
        self::assertStringContainsString('ruleset: architecture.md', $config);
    }

    public function testRefusesToOverwriteExistingFilesWithoutForce(): void
    {
        file_put_contents('architecture.md', 'existing content');

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('architecture.md already exist', $tester->getDisplay());
        self::assertSame('existing content', file_get_contents('architecture.md'));
        self::assertFileDoesNotExist('spandrel.yaml');
    }

    public function testForceOverwritesExistingFiles(): void
    {
        file_put_contents('architecture.md', 'existing content');

        $exitCode = $this->tester()->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('## Layers', (string) file_get_contents('architecture.md'));
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new InitCommand());

        return new CommandTester($application->find('init'));
    }
}
