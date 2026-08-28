<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Reporting;

use Spandrel\Spandrel\RuleEngine\Violation;

/**
 * Machine-readable, stable schema, one entry per `Violation` — no use
 * for the Code Graph or Ruleset.
 */
final class JsonReporter implements Reporter
{
    public function format(array $violations): string
    {
        $entries = array_map(static fn (Violation $violation): array => [
            'rule' => $violation->rule,
            'from' => $violation->fromElement,
            'to' => $violation->toElement,
            'kind' => $violation->dependencyKind->value,
            'file' => $violation->file,
            'line' => $violation->line,
            'message' => $violation->message,
        ], $violations);

        $json = json_encode(['violations' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{"violations":[]}' : $json."\n";
    }
}
