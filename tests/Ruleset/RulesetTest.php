<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Tests\Ruleset;

use PHPUnit\Framework\TestCase;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;
use Spandrel\Spandrel\Ruleset\Layer;
use Spandrel\Spandrel\Ruleset\Ruleset;
use Spandrel\Spandrel\Ruleset\RulesetParser;

final class RulesetTest extends TestCase
{
    public function testLayerNamedInARuleIsUsed(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Infrastructure**: `App\Infrastructure\**`
            - **Unused**: `App\Unused\**`

            ## Rules

            - `Domain` must not depend on `Infrastructure`
            MARKDOWN);

        self::assertSame(['Unused'], $this->unusedNames($ruleset));
    }

    public function testLayerDeclaredMayDependOnAnythingIsUsed(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Shared**: `App\Shared\**`

            ## Rules

            - `Shared` may depend on anything
            MARKDOWN);

        self::assertSame([], $this->unusedNames($ruleset));
    }

    public function testLeafCoveredOnlyThroughGroupsIsUsed(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **RunsDomain**: `App\Runs\Domain\**`
            - **RunsInfrastructure**: `App\Runs\Infrastructure\**`
            - **Runs** groups `RunsDomain` and `RunsInfrastructure`
            - **Domain** groups `RunsDomain`
            - **Infrastructure** groups `RunsInfrastructure`

            ## Rules

            - `Runs` may only depend on `Runs`
            - `Domain` must not depend on `Infrastructure`
            MARKDOWN);

        self::assertSame([], $this->unusedNames($ruleset));
    }

    public function testMembersOfNestedGroupsAreUsedThroughTheOutermostGroup(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Persistence**: `App\Persistence\**`
            - **Messaging**: `App\Messaging\**`
            - **IO** groups `Persistence` and `Messaging`
            - **Infrastructure** groups `IO`

            ## Rules

            - `Domain` must not depend on `Infrastructure`
            MARKDOWN);

        self::assertSame([], $this->unusedNames($ruleset));
    }

    public function testLeafInsideAGroupDeclaredMayDependOnAnythingIsUsed(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Controllers**: `App\Web\Controller\**`
            - **Templates**: `App\Web\Twig\**`
            - **Web** groups `Controllers` and `Templates`

            ## Rules

            - `Web` may depend on anything
            MARKDOWN);

        self::assertSame([], $this->unusedNames($ruleset));
    }

    public function testGroupNobodyReferencesIsUnusedEvenIfItsMembersAre(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - **Domain**: `App\Domain\**`
            - **Infrastructure**: `App\Infrastructure\**`
            - **IO** groups `Infrastructure`

            ## Rules

            - `Domain` must not depend on `Infrastructure`
            MARKDOWN);

        self::assertSame(['IO'], $this->unusedNames($ruleset));
    }

    public function testDerivedModuleWithoutRulesIsUnusedButItsLeavesAreCoveredByLayerGroups(): void
    {
        $ruleset = (new RulesetParser())->parse(<<<'MARKDOWN'
            ## Layers

            - `App\{Module}\{Layer}\**`

            ## Rules

            - `Domain` must not depend on `Infrastructure`
            - `Runs` may only depend on `Runs` and `Projects`
            MARKDOWN, $this->elements(
            'App\Projects\Domain\Project',
            'App\Runs\Domain\Run',
            'App\Runs\Infrastructure\RunRepository',
            'App\Results\Domain\Result',
        ));

        self::assertSame(['Results'], $this->unusedNames($ruleset));
    }

    /**
     * @return string[]
     */
    private function unusedNames(Ruleset $ruleset): array
    {
        return array_values(array_map(static fn (Layer $layer): string => $layer->name, $ruleset->unusedLayers()));
    }

    /**
     * @return Element[]
     */
    private function elements(string ...$fqcns): array
    {
        return array_map(
            static fn (string $fqcn): Element => new Element($fqcn, ElementKind::ClassLike, 'file.php', 1),
            $fqcns,
        );
    }
}
