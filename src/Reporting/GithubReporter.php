<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Reporting;

use Spandrel\Spandrel\RuleEngine\Violation;

/**
 * GitHub Actions workflow commands (`::error file=...,line=...::message`),
 * one per violation, so a run inside a workflow annotates the offending
 * lines in the pull request's diff without a SARIF upload or GitHub
 * Advanced Security in the picture.
 *
 * Unlike the other `Reporter`s this one can't report on `$violations`
 * alone: `Violation::$file` is relative to the source path it was found
 * under (`Domain/Foo.php` for `analyse src`), while GitHub resolves an
 * annotation's `file=` against the repository root and silently drops any
 * annotation whose path doesn't exist there. `$sourcePaths` is the same
 * list `analyse` walked, so prefixing the first one that actually
 * contains the file recovers the repo-root-relative path — which also
 * means annotations land only when `analyse` runs from the repository
 * root, the normal shape of a workflow step.
 *
 * No violations means no output at all: a workflow command is the whole
 * message here, so there's nothing to say when there's nothing to
 * annotate.
 */
final class GithubReporter implements Reporter
{
    /**
     * @param string[] $sourcePaths the paths `analyse` walked, used to turn
     *                              each violation's source-relative file back
     *                              into a repo-root-relative one
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

        // The rule text, not just "Spandrel" — the annotation's heading is
        // the one place a mixed-tool check run can say which tool spoke and
        // which rule was broken, the same thing `console -v` prints.
        $properties['title'] = 'Spandrel: '.$violation->rule;

        $formatted = [];

        foreach ($properties as $name => $value) {
            $formatted[] = $name.'='.self::escapeProperty($value);
        }

        return $formatted;
    }

    /**
     * `$file` prefixed with whichever source path actually holds it, then
     * made relative to the working directory so the result is repo-root-
     * relative (and `..`-free) whatever shape the source path had. Falls
     * back to `$file` unchanged when nothing matches — a wrong-looking
     * annotation is more useful than a silently dropped one.
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

    /**
     * Workflow-command message text: only `%`, CR and LF are special.
     */
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
