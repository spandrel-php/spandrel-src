<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scaffolds a starter `architecture.md` and `spandrel.yaml` in the current
 * working directory — a quick start, not a fully-configured setup: the
 * example layers/rules are a generic four-layer shape meant to be edited
 * to match the project's actual namespaces.
 */
#[AsCommand(name: 'init', description: 'Scaffold a starter architecture.md and spandrel.yaml')]
final class InitCommand extends Command
{
    private const ARCHITECTURE_TEMPLATE = <<<'MD'
        # Architecture

        ## Layers

        - **Domain**: `App\Domain\**`
        - **Application**: `App\Application\**`
        - **Infrastructure**: `App\Infrastructure\**`
        - **Console**: `App\Console\**`

        ## Rules

        - `Domain` depends on nothing
        - `Application` may only depend on `Domain`
        - `Infrastructure` may only depend on `Domain` and `Application`
        - `Console` may depend on anything

        MD;

    private const CONFIG_TEMPLATE = <<<'YAML'
        source:
            paths:
                - src
        ruleset: architecture.md

        YAML;

    private const FILES = [
        'architecture.md' => self::ARCHITECTURE_TEMPLATE,
        'spandrel.yaml' => self::CONFIG_TEMPLATE,
    ];

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite architecture.md/spandrel.yaml if they already exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        if (!$force) {
            $existing = array_values(array_filter(array_keys(self::FILES), static fn (string $path): bool => is_file($path)));

            if ($existing !== []) {
                $io->error(sprintf('%s already exist — pass --force to overwrite', implode(' and ', $existing)));

                return Command::FAILURE;
            }
        }

        foreach (self::FILES as $path => $contents) {
            file_put_contents($path, $contents);
        }

        $io->success(sprintf(
            'Wrote %s. Edit architecture.md to match your project\'s namespaces, then run `spandrel analyse`.',
            implode(' and ', array_keys(self::FILES)),
        ));

        return Command::SUCCESS;
    }
}
