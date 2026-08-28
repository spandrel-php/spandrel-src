<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Console;

use Spandrel\Spandrel\Cache\Cache;
use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Config\ConfigParseException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'cache:clear', description: 'Delete the cache directory')]
final class ClearCacheCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to the tool config file', null)
            ->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Directory to clear; defaults to cache.directory in spandrel.yaml');
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

        /** @var string|null $cacheDirOption */
        $cacheDirOption = $input->getOption('cache-dir');
        $cacheDirectory = $cacheDirOption ?? ($config !== null ? $config->cacheDirectory : null);

        if ($cacheDirectory === null) {
            $io->error('No cache directory given — pass --cache-dir or set cache.directory in spandrel.yaml');

            return Command::FAILURE;
        }

        (new Cache($cacheDirectory))->clear();

        $io->success(sprintf('Cleared cache at %s', $cacheDirectory));

        return Command::SUCCESS;
    }
}
