<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Reporting;

/**
 * Human-facing default format. A pure string (no `SymfonyStyle` access),
 * so `SymfonyStyle::success()`/`error()`'s padded banner box and
 * `section()`'s underline are deliberately not reproduced here — same
 * information (file grouping, colorized `<error>` lines, summary count,
 * `Rule:` text under `-v`) as plain colorized text instead of decorative
 * chrome that needs a live `OutputInterface` to render.
 */
final class ConsoleReporter implements Reporter
{
    public function __construct(
        private readonly bool $verbose = false,
        private readonly int $baselinedCount = 0,
    ) {
    }

    public function format(array $violations): string
    {
        if ($violations === []) {
            return $this->baselinedCount > 0
                ? sprintf("<info>No new violations found (%d baselined).</info>\n", $this->baselinedCount)
                : "<info>No violations found.</info>\n";
        }

        $byFile = [];

        foreach ($violations as $violation) {
            $byFile[$violation->file][] = $violation;
        }

        $lines = [];

        foreach ($byFile as $file => $fileViolations) {
            $lines[] = $file;

            foreach ($fileViolations as $violation) {
                $lines[] = sprintf('  <error>Line %d:</error> %s', $violation->line, $violation->message);

                if ($this->verbose) {
                    $lines[] = sprintf('    Rule: %s', $violation->rule);
                }
            }
        }

        $violationCount = count($violations);
        $fileCount = count($byFile);

        $lines[] = '';
        $lines[] = sprintf(
            '<error>%d violation%s across %d file%s</error>%s',
            $violationCount,
            $violationCount === 1 ? '' : 's',
            $fileCount,
            $fileCount === 1 ? '' : 's',
            $this->baselinedCount > 0 ? sprintf(' (%d baselined)', $this->baselinedCount) : '',
        );

        return implode("\n", $lines)."\n";
    }
}
