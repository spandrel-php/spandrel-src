<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Baseline;

use Spandrel\Spandrel\RuleEngine\Violation;

/**
 * A set of known `(from, to, kind)` violation tuples — deliberately not
 * file+line: looser than an exact location match, so it survives
 * incidental line churn nearby, at the accepted cost of also absorbing
 * a genuinely new violation that happens to reuse the same element pair.
 */
final class Baseline
{
    /**
     * @param array<string, array{from: string, to: string, kind: string}> $entries keyed by "from|to|kind"
     */
    private function __construct(private readonly array $entries)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param Violation[] $violations
     */
    public static function fromViolations(array $violations): self
    {
        $entries = [];

        foreach ($violations as $violation) {
            $entries[self::key($violation->fromElement, $violation->toElement, $violation->dependencyKind->value)] = [
                'from' => $violation->fromElement,
                'to' => $violation->toElement,
                'kind' => $violation->dependencyKind->value,
            ];
        }

        return new self($entries);
    }

    /**
     * @throws BaselineParseException
     */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            return self::empty();
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw BaselineParseException::unreadable($path);
        }

        $data = json_decode($contents, true);

        if (!is_array($data) || !isset($data['violations']) || !is_array($data['violations'])) {
            throw BaselineParseException::malformed($path);
        }

        $entries = [];

        foreach ($data['violations'] as $entry) {
            if (
                !is_array($entry)
                || !isset($entry['from'], $entry['to'], $entry['kind'])
                || !is_string($entry['from'])
                || !is_string($entry['to'])
                || !is_string($entry['kind'])
            ) {
                throw BaselineParseException::malformed($path);
            }

            $entries[self::key($entry['from'], $entry['to'], $entry['kind'])] = [
                'from' => $entry['from'],
                'to' => $entry['to'],
                'kind' => $entry['kind'],
            ];
        }

        return new self($entries);
    }

    public function contains(Violation $violation): bool
    {
        return isset($this->entries[self::key($violation->fromElement, $violation->toElement, $violation->dependencyKind->value)]);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function write(string $path): void
    {
        $payload = ['violations' => array_values($this->entries)];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private static function key(string $from, string $to, string $kind): string
    {
        return $from.'|'.$to.'|'.$kind;
    }
}
