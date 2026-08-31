<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Version;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Version\Version;

final class VersionTest extends TestCase
{
    public function testCurrentFallsBackToDevWhenBoxNeverReplacedThePlaceholder(): void
    {
        self::assertSame('dev', Version::current());
    }
}
