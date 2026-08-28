<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Console;

use Spandrel\Spandrel\Baseline\Baseline;
use Spandrel\Spandrel\Baseline\BaselineParseException;
use Spandrel\Spandrel\Cache\Cache;
use Spandrel\Spandrel\Config\ConfigLoader;
use Spandrel\Spandrel\Config\ConfigParseException;
use Spandrel\Spandrel\Graph\CodeGraph;
use Spandrel\Spandrel\Reporting\ConsoleReporter;
use Spandrel\Spandrel\Reporting\GraphReporter;
use Spandrel\Spandrel\Reporting\JsonReporter;
use Spandrel\Spandrel\Reporting\MermaidDiagramTooLargeException;
use Spandrel\Spandrel\Reporting\MermaidReporter;
use Spandrel\Spandrel\Reporting\Reporter;
use Spandrel\Spandrel\Reporting\SarifReporter;
use Spandrel\Spandrel\RuleEngine\RuleEngine;
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
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Main entry point: runs the full pipeline (ruleset parse, source parse,
 * layer resolution, Rule Engine) and reports violations via one of the
 * `Reporting\Reporter` implementations.
 *
 * `--strict` bundles two independent checks (`RulesetMeta::$strictElements`,
 * `RulesetMeta::$strictParsing`); either can also be turned on by the
 * ruleset itself via a `## Meta` sentence. `--no-strict` forces both off
 * regardless of what the ruleset declares.
 */
#[AsCommand(name: 'analyse', aliases: ['analyze'], description: 'Run the pipeline and report violations')]
final class AnalyseCommand extends Command
{
    private const SUPPORTED_FORMATS = ['console', 'json', 'sarif', 'mermaid'];
    private const SUPPORTED_DIAGRAM_SCOPES = ['full', 'violations'];

    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly RulesetLoader $rulesetLoader,
        private readonly SourceGraphBuilder $sourceGraphBuilder,
        private readonly RuleEngine $ruleEngine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::OPTIONAL, 'Source directory to analyse; defaults to source.paths in spandrel.yaml')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to the tool config file', null)
            ->addOption('ruleset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path to a ruleset file; repeatable to merge several', [])
            ->addOption('report', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'FORMAT or FORMAT:OUTPUT (e.g. sarif:report.sarif); repeatable to emit several reports in one run. FORMAT is console (default), json, sarif, or mermaid; OUTPUT defaults to "-" for stdout', [])
            ->addOption('diagram-scope', null, InputOption::VALUE_REQUIRED, 'Mermaid only: full (default) or violations', 'full')
            ->addOption('diagram-layer', null, InputOption::VALUE_REQUIRED, 'Mermaid only: scope the diagram to one layer and its immediate neighbors', null)
            ->addOption('diagram-force', null, InputOption::VALUE_NONE, 'Mermaid only: render the full diagram even if it exceeds the size that stays readable')
            ->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Directory to cache parsed file results in; defaults to cache.directory in spandrel.yaml')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable caching even if cache.directory is configured')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Path to a baseline file to suppress known violations; defaults to baseline in spandrel.yaml')
            ->addOption('generate-baseline', null, InputOption::VALUE_NONE, 'Write current violations to the baseline file instead of failing')
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Disable baseline suppression even if baseline is configured')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on a PHP parse error or an element not covered by any layer, instead of skipping silently')
            ->addOption('no-strict', null, InputOption::VALUE_NONE, 'Disable strict mode even if the ruleset declares it in `## Meta`');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string[] $reportOptions */
        $reportOptions = (array) $input->getOption('report');

        /** @var array<int, array{format: string, output: string}> $reportSpecs */
        $reportSpecs = [];

        if ($reportOptions === []) {
            $reportSpecs[] = ['format' => 'console', 'output' => '-'];
        }

        foreach ($reportOptions as $reportOption) {
            $parts = explode(':', $reportOption, 2);
            $reportFormat = $parts[0];
            $reportOutput = $parts[1] ?? '-';

            if ($reportFormat === '' || $reportOutput === '') {
                $io->error(sprintf('Malformed --report value "%s". Expected FORMAT or FORMAT:OUTPUT.', $reportOption));

                return Command::INVALID;
            }

            $reportSpecs[] = ['format' => $reportFormat, 'output' => $reportOutput];
        }

        /** @var string[] $unsupportedFormats */
        $unsupportedFormats = array_values(array_unique(array_filter(
            array_column($reportSpecs, 'format'),
            static fn (string $format): bool => !in_array($format, self::SUPPORTED_FORMATS, true),
        )));

        if ($unsupportedFormats !== []) {
            foreach ($unsupportedFormats as $unsupportedFormat) {
                $io->error(sprintf(
                    'Unsupported format "%s". Supported: %s',
                    $unsupportedFormat,
                    implode(', ', self::SUPPORTED_FORMATS),
                ));
            }

            return Command::INVALID;
        }

        $usesMermaid = in_array('mermaid', array_column($reportSpecs, 'format'), true);

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

        if ($sourcePaths === []) {
            $io->error('No source paths given — pass a paths argument or set source.paths in spandrel.yaml');

            return Command::INVALID;
        }

        /** @var string|null $cacheDirOption */
        $cacheDirOption = $input->getOption('cache-dir');
        $noCache = (bool) $input->getOption('no-cache');
        $cacheDirectory = $noCache ? null : ($cacheDirOption ?? ($config !== null ? $config->cacheDirectory : null));
        $cache = $cacheDirectory !== null ? new Cache($cacheDirectory) : null;

        $noStrict = (bool) $input->getOption('no-strict');
        $strictOption = (bool) $input->getOption('strict');

        // Loaded before the ruleset so it can derive {Name} placeholder layers
        // against the discovered Elements.
        [$elements, $dependencies, $parseErrors] = $this->sourceGraphBuilder->build($sourcePaths, $cache, $exclude);

        /** @var string[] $rulesetOptions */
        $rulesetOptions = (array) $input->getOption('ruleset');
        $rulesetPaths = $rulesetOptions !== [] ? $rulesetOptions : (($config !== null && $config->ruleset !== null) ? [$config->ruleset] : ['architecture.md']);

        try {
            $ruleset = $this->rulesetLoader->load($rulesetPaths, $elements);
        } catch (RulesetParseException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $failOnUnmatched = $noStrict ? false : ($strictOption || $ruleset->meta->strictElements);
        $failOnParseError = $noStrict ? false : ($strictOption || $ruleset->meta->strictParsing);

        if ($failOnParseError && $parseErrors !== []) {
            foreach ($parseErrors as $parseError) {
                $io->error(sprintf('%s: %s', $parseError->file, $parseError->message));
            }

            return Command::INVALID;
        }

        try {
            $resolution = (new LayerResolver($ruleset->layers))->resolve($elements);
        } catch (AmbiguousLayerMatchException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if ($failOnUnmatched && $resolution->unmatched !== []) {
            foreach ($resolution->unmatched as $element) {
                $io->error(sprintf('%s is not covered by any layer', $element->fqcn));
            }

            return Command::FAILURE;
        }

        $violations = $this->ruleEngine->evaluate($ruleset, $resolution, $dependencies);

        /** @var string|null $baselineOption */
        $baselineOption = $input->getOption('baseline');
        $noBaseline = (bool) $input->getOption('no-baseline');
        $baselinePath = $noBaseline ? null : ($baselineOption ?? ($config !== null ? $config->baselinePath : null));

        if ((bool) $input->getOption('generate-baseline')) {
            if ($baselinePath === null) {
                $io->error('No baseline path given — pass --baseline or set baseline in spandrel.yaml');

                return Command::INVALID;
            }

            Baseline::fromViolations($violations)->write($baselinePath);

            $violationCount = count($violations);
            $io->success(sprintf(
                'Wrote %d violation%s to %s',
                $violationCount,
                $violationCount === 1 ? '' : 's',
                $baselinePath,
            ));

            return Command::SUCCESS;
        }

        $baselinedCount = 0;

        if ($baselinePath !== null) {
            try {
                $baseline = Baseline::load($baselinePath);
            } catch (BaselineParseException $e) {
                $io->error($e->getMessage());

                return Command::INVALID;
            }

            $newViolations = [];

            foreach ($violations as $violation) {
                if ($baseline->contains($violation)) {
                    $baselinedCount++;
                } else {
                    $newViolations[] = $violation;
                }
            }

            $violations = $newViolations;
        }

        /** @var string $diagramScope */
        $diagramScope = $input->getOption('diagram-scope');

        if ($usesMermaid && !in_array($diagramScope, self::SUPPORTED_DIAGRAM_SCOPES, true)) {
            $io->error(sprintf(
                'Unsupported diagram scope "%s". Supported: %s',
                $diagramScope,
                implode(', ', self::SUPPORTED_DIAGRAM_SCOPES),
            ));

            return Command::INVALID;
        }

        /** @var string|null $diagramLayer */
        $diagramLayer = $input->getOption('diagram-layer');

        if ($usesMermaid && $diagramLayer !== null && !self::layerExists($diagramLayer, $ruleset->layers)) {
            $io->error(sprintf('Unknown layer "%s" for --diagram-layer', $diagramLayer));

            return Command::INVALID;
        }

        /** @var bool $diagramForce */
        $diagramForce = $input->getOption('diagram-force');

        // Rendered into memory before anything is written, so a failure partway through
        // doesn't leave some report files written and others missing.
        /** @var array<int, array{target: string, text: string, decorated: bool}> $writes */
        $writes = [];
        $graph = null;

        foreach ($reportSpecs as $spec) {
            $reporterInstance = $this->reporter($spec['format'], $output->isVerbose(), $diagramScope, $diagramLayer, $diagramForce, $baselinedCount);

            try {
                if ($reporterInstance instanceof GraphReporter) {
                    $graph ??= CodeGraph::fromElements($elements, $dependencies);
                    $formatted = $reporterInstance->format($violations, $graph, $ruleset);
                } else {
                    $formatted = $reporterInstance->format($violations);
                }
            } catch (MermaidDiagramTooLargeException $e) {
                $io->error($e->getMessage());

                return Command::INVALID;
            }

            $writes[] = ['target' => $spec['output'], 'text' => $formatted, 'decorated' => $spec['format'] === 'console'];
        }

        foreach ($writes as $write) {
            $this->write($output, $write['target'], $write['text'], $write['decorated']);
        }

        return $violations === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function reporter(string $format, bool $verbose, string $diagramScope, ?string $diagramLayer, bool $diagramForce, int $baselinedCount): Reporter|GraphReporter
    {
        return match ($format) {
            'json' => new JsonReporter(),
            'sarif' => new SarifReporter(),
            'mermaid' => new MermaidReporter($diagramScope === 'violations', $diagramLayer, $diagramForce),
            default => new ConsoleReporter($verbose, $baselinedCount),
        };
    }

    /**
     * @param Layer[] $layers
     */
    private static function layerExists(string $name, array $layers): bool
    {
        foreach ($layers as $layer) {
            if ($layer->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes `$text` to `$target` ("-" for stdout). `$decorated` selects whether
     * `<tag>`-shaped sequences are interpreted as console markup or left
     * untouched — needed since a violation message can legitimately contain
     * `<`/`>` (e.g. `Collection<Foo>`) for json/sarif/mermaid output.
     */
    private function write(OutputInterface $output, string $target, string $text, bool $decorated): void
    {
        $targetOutput = $output;

        if ($target !== '-') {
            $stream = fopen($target, 'w');

            if ($stream !== false) {
                $targetOutput = new StreamOutput($stream, $output->getVerbosity());
            }
        }

        $targetOutput->write($text, false, $decorated ? OutputInterface::OUTPUT_NORMAL : OutputInterface::OUTPUT_RAW);
    }
}
