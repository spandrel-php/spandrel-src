<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Reporting;

use Spandrel\Spandrel\RuleEngine\Violation;
use Spandrel\Spandrel\Version\Version;

/**
 * SARIF 2.1.0 for CI code-scanning integrations.
 *
 * `tool.driver.rules[]` is built from the distinct rule-text strings
 * present in `$violations`, not from the Ruleset's own rule list — a
 * `may only depend on` violation's rule text is a synthesis of an entire
 * rule group, so a bullet-by-bullet catalog wouldn't map 1:1 to
 * `$violations` anyway.
 *
 * `region` drops absent `startColumn`/`endLine`/`endColumn` fields rather
 * than emitting them as `null`, since a region with only `startLine` is
 * itself valid SARIF.
 */
final class SarifReporter implements Reporter
{
    public function format(array $violations): string
    {
        $rulesById = [];

        foreach ($violations as $violation) {
            $id = self::ruleId($violation->rule);
            $rulesById[$id] ??= [
                'id' => $id,
                'shortDescription' => ['text' => $violation->rule],
            ];
        }

        $results = array_map(static fn (Violation $violation): array => [
            'ruleId' => self::ruleId($violation->rule),
            'level' => 'error',
            'message' => ['text' => $violation->message],
            'locations' => [
                [
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => str_replace('\\', '/', $violation->file)],
                        'region' => self::region($violation),
                    ],
                ],
            ],
        ], $violations);

        $document = [
            '$schema' => 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => 'Spandrel',
                            'version' => Version::current(),
                            'rules' => array_values($rulesById),
                        ],
                    ],
                    'results' => $results,
                ],
            ],
        ];

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json."\n";
    }

    private static function ruleId(string $ruleText): string
    {
        return substr(hash('xxh3', $ruleText), 0, 12);
    }

    /**
     * @return array<string, int>
     */
    private static function region(Violation $violation): array
    {
        return array_filter([
            'startLine' => $violation->line,
            'startColumn' => $violation->column,
            'endLine' => $violation->endLine,
            'endColumn' => $violation->endColumn,
        ], static fn (?int $value): bool => $value !== null);
    }
}
