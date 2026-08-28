<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Console;

use Spandrel\Spandrel\Cache\Cache;
use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Config\ConfigParseException;
use Spandrel\Spandrel\Ruleset\LayerResolver;
use Spandrel\Spandrel\Ruleset\RulesetParseException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'debug:layers', description: 'Show every declared layer and what it resolved to')]
final class DebugLayersCommand extends Command
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
            ->addArgument('path', InputArgument::OPTIONAL, 'Source directory to analyse; defaults to source.paths in spandrel.yaml')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to the tool config file', null)
            ->addOption('ruleset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path to a ruleset file; repeatable to merge several', [])
            ->addOption('show-elements', null, InputOption::VALUE_NONE, 'List every matched FQCN, not just the count')
            ->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Directory to cache parsed file results in; defaults to cache.directory in spandrel.yaml')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable caching even if cache.directory is configured');
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

            return Command::FAILURE;
        }

        /** @var string|null $pathArgument */
        $pathArgument = $input->getArgument('path');
        $sourcePaths = $pathArgument !== null ? [$pathArgument] : ($config !== null ? $config->sourcePaths : []);
        $exclude = $config !== null ? $config->excludePaths : [];

        /** @var string[] $rulesetOptions */
        $rulesetOptions = (array) $input->getOption('ruleset');
        $rulesetPaths = $rulesetOptions !== [] ? $rulesetOptions : (($config !== null && $config->ruleset !== null) ? [$config->ruleset] : ['architecture.md']);
        $showElements = (bool) $input->getOption('show-elements');

        if ($sourcePaths === []) {
            $io->error('No source path given — pass a path argument or set source.paths in spandrel.yaml');

            return Command::FAILURE;
        }

        /** @var string|null $cacheDirOption */
        $cacheDirOption = $input->getOption('cache-dir');
        $noCache = (bool) $input->getOption('no-cache');
        $cacheDirectory = $noCache ? null : ($cacheDirOption ?? ($config !== null ? $config->cacheDirectory : null));
        $cache = $cacheDirectory !== null ? new Cache($cacheDirectory) : null;

        // Loaded before the ruleset so RulesetParser can derive {Name} placeholder
        // layers against the actual discovered Elements.
        [$elements] = $this->sourceGraphBuilder->build($sourcePaths, $cache, $exclude);

        try {
            $ruleset = $this->rulesetLoader->load($rulesetPaths, $elements);
        } catch (RulesetParseException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($ruleset->layers === []) {
            $io->warning(sprintf('No layers declared in %s', implode(', ', $rulesetPaths)));
        }

        $resolution = (new LayerResolver($ruleset->layers))->resolve($elements);

        $rows = [];

        foreach ($ruleset->layers as $layer) {
            $matched = $resolution->matches[$layer->name];
            $name = $layer->isExternal ? $layer->name.' (external)' : $layer->name;
            $rows[] = [$name, implode(', ', $layer->patterns), (string) count($matched)];
        }

        $io->table(['Layer', 'Patterns', 'Matched'], $rows);

        if ($showElements) {
            foreach ($ruleset->layers as $layer) {
                $matched = $resolution->matches[$layer->name];

                if ($matched === []) {
                    continue;
                }

                $io->section($layer->name);

                foreach ($matched as $element) {
                    $io->writeln(sprintf('  %s (%s)', $element->fqcn, $element->kind->value));
                }
            }
        }

        $io->writeln(sprintf('Unmatched: %d', count($resolution->unmatched)));

        if ($showElements) {
            foreach ($resolution->unmatched as $element) {
                $io->writeln(sprintf('  %s (%s)', $element->fqcn, $element->kind->value));
            }
        }

        return Command::SUCCESS;
    }
}
