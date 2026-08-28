<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Console;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Console\RulesetLoader;
use Spandrel\Spandrel\Ruleset\RulesetParseException;
use Spandrel\Spandrel\Ruleset\RulesetParser;

final class RulesetLoaderTest extends TestCase
{
    public function testLoadsAndMergesTwoRealFiles(): void
    {
        $fixtures = __DIR__.'/../Fixtures/DemoApp';
        $loader = new RulesetLoader(new RulesetParser());

        $ruleset = $loader->load([
            $fixtures.'/architecture-split-layers.md',
            $fixtures.'/architecture-split-rules.md',
        ], []);

        self::assertCount(2, $ruleset->layers);
        self::assertCount(1, $ruleset->rules);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(RulesetParseException::class);
        $this->expectExceptionMessage('not found');

        (new RulesetLoader(new RulesetParser()))->load(['/does/not/exist.md'], []);
    }

    public function testUnreadableFileThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'spandrel-ruleset-');
        self::assertIsString($path);
        file_put_contents($path, "## Layers\n\n- **Domain**: `App\\Domain\\**`\n");
        chmod($path, 0000);

        try {
            if (is_readable($path)) {
                self::markTestSkipped('Cannot make a file unreadable in this environment (likely running as root).');
            }

            $this->expectException(RulesetParseException::class);
            $this->expectExceptionMessage('Could not read');

            (new RulesetLoader(new RulesetParser()))->load([$path], []);
        } finally {
            chmod($path, 0644);
            unlink($path);
        }
    }
}
