<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Console;

use Spandrel\Spandrel\Cache\Cache;
use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Config\ConfigParseException;
use Spandrel\Spandrel\Ruleset\RulesetParseException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the ruleset back out with every piece of sugar resolved: list
 * bullets expanded to their individual pairwise rules, `depends on
 * nothing` expanded to its full `must not depend on` target list, `may
 * depend on anything` shown explicitly, and `{Name}` placeholder layers
 * expanded to the concrete layers they derived.
 *
 * `## Meta` is shown first, only when at least one policy sentence is
 * actually true.
 *
 * `paths` is optional: placeholder derivation needs the Code Graph, so
 * without any source a placeholder bullet shows its raw template line
 * instead of silently vanishing.
 */
#[AsCommand(name: 'debug:ruleset', description: 'Print the ruleset fully expanded')]
final class DebugRulesetCommand extends Command
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
            ->addArgument('paths', InputArgument::OPTIONAL, 'Source to resolve {Name} placeholder layers against; defaults to source.paths in spandrel.yaml')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to the tool config file', null)
            ->addOption('ruleset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path to a ruleset file; repeatable to merge several', [])
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
        $pathArgument = $input->getArgument('paths');
        $sourcePaths = $pathArgument !== null ? [$pathArgument] : ($config !== null ? $config->sourcePaths : []);
        $exclude = $config !== null ? $config->excludePaths : [];

        /** @var string|null $cacheDirOption */
        $cacheDirOption = $input->getOption('cache-dir');
        $noCache = (bool) $input->getOption('no-cache');
        $cacheDirectory = $noCache ? null : ($cacheDirOption ?? ($config !== null ? $config->cacheDirectory : null));
        $cache = $cacheDirectory !== null ? new Cache($cacheDirectory) : null;

        [$elements] = $this->sourceGraphBuilder->build($sourcePaths, $cache, $exclude);

        /** @var string[] $rulesetOptions */
        $rulesetOptions = (array) $input->getOption('ruleset');
        $rulesetPaths = $rulesetOptions !== [] ? $rulesetOptions : (($config !== null && $config->ruleset !== null) ? [$config->ruleset] : ['architecture.md']);

        try {
            $ruleset = $this->rulesetLoader->load($rulesetPaths, $elements);
        } catch (RulesetParseException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($ruleset->meta->strictElements || $ruleset->meta->strictParsing || $ruleset->meta->strictLayers) {
            $io->writeln('## Meta');
            $io->newLine();

            if ($ruleset->meta->strictElements) {
                $io->writeln('- Any class not in a layer violates rules.');
            }

            if ($ruleset->meta->strictParsing) {
                $io->writeln('- A file that fails to parse violates rules.');
            }

            if ($ruleset->meta->strictLayers) {
                $io->writeln('- Every layer must be used in a rule.');
            }

            $io->newLine();
        }

        $io->writeln('## Layers');
        $io->newLine();

        foreach ($ruleset->layers as $layer) {
            $patterns = implode(', ', array_map(static fn (string $pattern): string => "`$pattern`", $layer->patterns));

            $io->writeln(sprintf('- **%s**: %s', $layer->name, $patterns));
        }

        // Only shown when there was no source to derive against.
        if ($elements === []) {
            foreach ($ruleset->placeholderTemplates as $template) {
                $io->writeln(sprintf('- `%s`', $template));
            }
        }

        $io->newLine();
        $io->writeln('## Rules');
        $io->newLine();

        foreach ($ruleset->layers as $layer) {
            if (in_array($layer->name, $ruleset->unconstrainedLayers, true)) {
                $io->writeln(sprintf('- `%s` may depend on anything', $layer->name));

                continue;
            }

            foreach ($ruleset->rules as $rule) {
                if ($rule->subject !== $layer->name) {
                    continue;
                }

                $io->writeln('- '.$rule->describe());
            }
        }

        // Inline-pattern subjects aren't a declared layer, so list them separately.
        foreach ($ruleset->rules as $rule) {
            if (!is_string($rule->subject)) {
                $io->writeln('- '.$rule->describe());
            }
        }

        return Command::SUCCESS;
    }
}
