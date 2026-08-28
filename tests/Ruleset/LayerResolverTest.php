<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Ruleset;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;
use Spandrel\Spandrel\Ruleset\AmbiguousLayerMatchException;
use Spandrel\Spandrel\Ruleset\Layer;
use Spandrel\Spandrel\Ruleset\LayerResolver;

final class LayerResolverTest extends TestCase
{
    public function testResolvesElementsToLayersAndCollectsUnmatched(): void
    {
        $domain = new Layer('Domain', ['App\Domain\**']);
        $infrastructure = new Layer('Infrastructure', ['App\Infrastructure\**']);

        $user = $this->element('App\Domain\User');
        $repository = $this->element('App\Infrastructure\PdoUserRepository');
        $config = $this->element('App\Shared\Config');

        $resolution = (new LayerResolver([$domain, $infrastructure]))
            ->resolve([$user, $repository, $config]);

        self::assertSame([$user], $resolution->matches['Domain']);
        self::assertSame([$repository], $resolution->matches['Infrastructure']);
        self::assertSame([$config], $resolution->unmatched);
    }

    public function testExternalLayerNeverClaimsARealElementEvenWhenItsPatternMatches(): void
    {
        $domain = new Layer('Domain', ['App\Domain\**']);
        $external = new Layer('LooksLikeDomain', ['App\Domain\**'], isExternal: true);

        $user = $this->element('App\Domain\User');

        $resolution = (new LayerResolver([$domain, $external]))->resolve([$user]);

        self::assertSame([$user], $resolution->matches['Domain']);
        self::assertSame([], $resolution->matches['LooksLikeDomain']);
        self::assertSame([], $resolution->unmatched);
    }

    public function testExternalLayerOverlappingARealLayerNeverTriggersAmbiguousMatch(): void
    {
        $domain = new Layer('Domain', ['App\Domain\**']);
        $external = new Layer('LooksLikeDomain', ['App\Domain\**'], isExternal: true);

        $resolution = (new LayerResolver([$domain, $external]))->resolve([$this->element('App\Domain\User')]);

        self::assertSame([], $resolution->unmatched);
    }

    public function testThrowsOnAmbiguousMatch(): void
    {
        $domain = new Layer('Domain', ['App\Domain\**']);
        $everything = new Layer('Everything', ['App\**']);

        $this->expectException(AmbiguousLayerMatchException::class);
        $this->expectExceptionMessage('App\Domain\User');

        (new LayerResolver([$domain, $everything]))->resolve([$this->element('App\Domain\User')]);
    }

    private function element(string $fqcn): Element
    {
        return new Element($fqcn, ElementKind::ClassLike, 'test.php', 1);
    }
}
