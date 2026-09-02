<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Reporting;

use Spandrel\Spandrel\RuleEngine\Violation;

/**
 * GitHub Actions workflow commands (`::error file=...,line=...::message`),
 * one per violation, annotating the pull request's diff without a SARIF
 * upload — see docs/report.md for the format's own contract.
 *
 * Unlike the other `Reporter`s this one can't work from `$violations`
 * alone: GitHub resolves an annotation's `file=` against the repository
 * root, while `Violation::$file` is relative to the source path it was
 * found under, hence `$sourcePaths` as constructor state.
 */
final class GithubReporter implements Reporter
{
    /**
     * @param string[] $sourcePaths the paths `analyse` walked
     */
    public function __construct(private readonly array $sourcePaths = [])
    {
    }

    public function format(array $violations): string
    {
        $lines = [];

        foreach ($violations as $violation) {
            $lines[] = sprintf(
                '::error %s::%s',
                implode(',', self::properties($violation, $this->annotationPath($violation->file))),
                self::escapeData($violation->message),
            );
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @return string[]
     */
    private static function properties(Violation $violation, string $file): array
    {
        $properties = [
            'file' => $file,
            'line' => (string) $violation->line,
        ];

        if ($violation->column !== null) {
            $properties['col'] = (string) $violation->column;
        }

        if ($violation->endLine !== null) {
            $properties['endLine'] = (string) $violation->endLine;
        }

        if ($violation->endColumn !== null) {
            $properties['endColumn'] = (string) $violation->endColumn;
        }

        // The rule text, not just "Spandrel": the heading is the one place a
        // mixed-tool check run can say which tool spoke and which rule broke.
        $properties['title'] = 'Spandrel: '.$violation->rule;

        $formatted = [];

        foreach ($properties as $name => $value) {
            $formatted[] = $name.'='.self::escapeProperty($value);
        }

        return $formatted;
    }

    /**
     * `$file` prefixed with whichever source path holds it, then made
     * relative to the working directory. Unmatched files are emitted
     * unchanged — a wrong-looking annotation beats a silently dropped one.
     */
    private function annotationPath(string $file): string
    {
        foreach ($this->sourcePaths as $sourcePath) {
            $sourcePath = rtrim($sourcePath, '/');

            // `analyse path/to/One.php` yields a bare basename, not a path
            // under a directory root — see `Loader::find()`.
            $candidate = basename($sourcePath) === $file && is_file($sourcePath)
                ? $sourcePath
                : $sourcePath.'/'.$file;

            if (!is_file($candidate)) {
                continue;
            }

            $absolute = realpath($candidate);
            $workingDirectory = getcwd();

            if ($absolute !== false && $workingDirectory !== false && str_starts_with($absolute, $workingDirectory.'/')) {
                return substr($absolute, strlen($workingDirectory) + 1);
            }

            return $candidate;
        }

        return $file;
    }

    private static function escapeData(string $value): string
    {
        return strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);
    }

    /**
     * Property values additionally escape the `,` and `:` that separate
     * properties from each other and from the message.
     */
    private static function escapeProperty(string $value): string
    {
        return strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A', ':' => '%3A', ',' => '%2C']);
    }
}
