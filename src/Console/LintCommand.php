<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Console;

use Spandrel\Spandrel\Cache\Cache;
use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Config\ConfigParseException;
use Spandrel\Spandrel\Ruleset\AmbiguousLayerMatchException;
use Spandrel\Spandrel\Ruleset\Layer;
use Spandrel\Spandrel\Ruleset\LayerResolver;
use Spandrel\Spandrel\Ruleset\RulesetParseException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validates a ruleset without evaluating any code for violations.
 *
 * Always checked: the ruleset parses (grammar, unique layer names, no
 * conflicting rule mode per subject), and — under `--strict-layers` —
 * that every declared layer either appears in some rule or is explicitly
 * declared `may depend on anything`. `--strict-layers` can also be turned
 * on by the ruleset itself (`## Meta`'s "Every layer must be used in a
 * rule."); `--no-strict-layers` forces it off regardless.
 *
 * Checked only when `paths` is given, since these need a real Code Graph:
 * no element matches more than one leaf layer (failure), and no declared,
 * non-external layer matches zero elements (warning only — an external
 * layer matching nothing is expected by design).
 */
#[AsCommand(name: 'lint', description: 'Validate architecture.md without evaluating violations')]
final class LintCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly RulesetLoader $rulesetLoader,
        private readonly SourceGraphBuilder $sourceGraphBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::OPTIONAL, 'Source to resolve layers against; defaults to source.paths in spandrel.yaml')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to the tool config file', null)
            ->addOption('ruleset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path to a ruleset file; repeatable to merge several', [])
            ->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Directory to cache parsed file results in; defaults to cache.directory in spandrel.yaml')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable caching even if cache.directory is configured')
            ->addOption('strict-layers', null, InputOption::VALUE_NONE, 'Fail if any layer is unused in every rule and not declared `may depend on anything`')
            ->addOption('no-strict-layers', null, InputOption::VALUE_NONE, 'Disable strict-layers mode even if the ruleset declares it in `## Meta`');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $configPath */
        $configPath = $input->getOption('config');

        try {
            $config = $this->configLoader->load($configPath);
        } catch (ConfigParseException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        /** @var string|null $pathArgument */
        $pathArgument = $input->getArgument('paths');
        $sourcePaths = $pathArgument !== null ? [$pathArgument] : ($config !== null ? $config->sourcePaths : []);
        $exclude = $config !== null ? $config->excludePaths : [];

        /** @var string|null $cacheDirOption */
        $cacheDirOption = $input->getOption('cache-dir');
        $noCache = (bool) $input->getOption('no-cache');
        $cacheDirectory = $noCache ? null : ($cacheDirOption ?? ($config !== null ? $config->cacheDirectory : null));
        $cache = $cacheDirectory !== null ? new Cache($cacheDirectory) : null;

        // Loaded before the ruleset so it can derive {Name} placeholder layers
        // against the discovered Elements.
        [$elements] = $this->sourceGraphBuilder->build($sourcePaths, $cache, $exclude);

        /** @var string[] $rulesetOptions */
        $rulesetOptions = (array) $input->getOption('ruleset');
        $rulesetPaths = $rulesetOptions !== [] ? $rulesetOptions : (($config !== null && $config->ruleset !== null) ? [$config->ruleset] : ['architecture.md']);
        $rulesetLabel = implode(', ', $rulesetPaths);

        try {
            $ruleset = $this->rulesetLoader->load($rulesetPaths, $elements);
        } catch (RulesetParseException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $noStrictLayers = (bool) $input->getOption('no-strict-layers');
        $strictLayersOption = (bool) $input->getOption('strict-layers');
        $strictLayers = $noStrictLayers ? false : ($strictLayersOption || $ruleset->meta->strictLayers);

        if ($strictLayers) {
            $usedNames = [];

            foreach ($ruleset->rules as $rule) {
                if (is_string($rule->subject)) {
                    $usedNames[$rule->subject] = true;
                }

                if (is_string($rule->object)) {
                    $usedNames[$rule->object] = true;
                }
            }

            $unconstrained = array_flip($ruleset->unconstrainedLayers);

            $staleLayers = array_filter(
                $ruleset->layers,
                static fn (Layer $layer): bool => !isset($usedNames[$layer->name]) && !isset($unconstrained[$layer->name]),
            );

            if ($staleLayers !== []) {
                foreach ($staleLayers as $layer) {
                    $io->error(sprintf('Layer "%s" is not used in any rule and isn\'t declared `may depend on anything`', $layer->name));
                }

                return Command::INVALID;
            }
        }

        if ($ruleset->layers === []) {
            $io->warning(sprintf('No layers declared in %s', $rulesetLabel));
        }

        if ($sourcePaths === []) {
            $io->success(sprintf('%s is valid', $rulesetLabel));

            return Command::SUCCESS;
        }

        try {
            $resolution = (new LayerResolver($ruleset->layers))->resolve($elements);
        } catch (AmbiguousLayerMatchException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        foreach ($ruleset->layers as $layer) {
            if (!$layer->isExternal && $resolution->matches[$layer->name] === []) {
                $io->warning(sprintf('Layer "%s" matched zero elements', $layer->name));
            }
        }

        $io->success(sprintf('%s is valid', $rulesetLabel));

        return Command::SUCCESS;
    }
}
