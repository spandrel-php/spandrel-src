<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

final class RulesetParseException extends \RuntimeException
{
    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('Ruleset file not found: %s', $path));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('Could not read ruleset file: %s', $path));
    }

    public static function malformedLayerBullet(int $line, string $text): self
    {
        return new self(sprintf(
            "line %d: could not parse layer bullet\n  %s\nExpected: - **Name**: `pattern`[, `pattern`...]",
            $line,
            trim($text),
        ));
    }

    public static function duplicateLayerName(int $line, string $name): self
    {
        return new self(sprintf('line %d: layer "%s" is already declared', $line, $name));
    }

    public static function malformedRuleBullet(int $line, string $text): self
    {
        return new self(sprintf(
            "line %d: could not parse rule bullet\n  %s\nExpected: `<Layer>` must not depend on `<Layer>` "
            ."(or may only depend on / depends on nothing / may depend on anything), "
            ."or a kind-scoped verb (must not/may only call functions defined in, "
            ."must not/may only instantiate objects from, must not/may only throw)",
            $line,
            trim($text),
        ));
    }

    public static function unknownLayerInRule(int $line, string $name): self
    {
        return new self(sprintf('line %d: rule references undeclared layer "%s"', $line, $name));
    }

    public static function conflictingRuleMode(int $line, string $subject): self
    {
        return new self(sprintf(
            'line %d: layer "%s" already has a conflicting rule mode declared for this scope '
            .'(must not / may only / depends on nothing / may depend on anything '
            .'are mutually exclusive per subject, within the same kind scope)',
            $line,
            $subject,
        ));
    }

    public static function reservedTargetWrongScope(int $line, string $target, string $expectedScope): self
    {
        return new self(sprintf(
            'line %d: `%s` is only valid on %s rules',
            $line,
            $target,
            $expectedScope,
        ));
    }

    public static function typeHierarchyTargetWrongScope(int $line): self
    {
        return new self(sprintf(
            'line %d: `subtypes of` is not valid on a call-scoped rule (functions have no type hierarchy)',
            $line,
        ));
    }

    public static function malformedMetaBullet(int $line, string $text): self
    {
        return new self(sprintf(
            "line %d: could not parse meta bullet\n  %s\n"
            ."Expected one of:\n"
            .'  - Any class not in a layer violates rules.'."\n"
            .'  - A file that fails to parse violates rules.'."\n"
            .'  - Every layer must be used in a rule.',
            $line,
            trim($text),
        ));
    }

    public static function malformedExceptClause(int $line, string $text): self
    {
        return new self(sprintf(
            "line %d: could not parse except clause\n  %s\nExpected: except `<Layer>`[, `<Layer>`...]",
            $line,
            trim($text),
        ));
    }

    public static function exceptDoesNotNest(int $line, string $text): self
    {
        return new self(sprintf(
            "line %d: `except` does not nest — an excepted operand can't itself carry another `except`\n  %s",
            $line,
            trim($text),
        ));
    }

    public static function exceptWithListNotSupported(int $line, string $text): self
    {
        return new self(sprintf(
            "line %d: `except` combined with a list of objects is not supported\n  %s",
            $line,
            trim($text),
        ));
    }

    public static function unknownLayerInLayerExcept(int $line, string $name): self
    {
        return new self(sprintf('line %d: layer except references undeclared layer "%s"', $line, $name));
    }

    public static function unknownLayerInGroup(int $line, string $groupName, string $memberName): self
    {
        return new self(sprintf(
            'line %d: group "%s" references undeclared layer "%s"',
            $line,
            $groupName,
            $memberName,
        ));
    }

    public static function groupCycleDetected(int $line, string $groupName): self
    {
        return new self(sprintf(
            'line %d: group "%s" is part of a cycle (a group cannot transitively contain itself)',
            $line,
            $groupName,
        ));
    }

    public static function exceptCycleDetected(int $line, string $layerName): self
    {
        return new self(sprintf(
            'line %d: layer "%s" is part of an `except` cycle (a layer cannot indirectly except itself)',
            $line,
            $layerName,
        ));
    }

    public static function malformedPlaceholderBullet(int $line, string $text): self
    {
        return new self(sprintf(
            "line %d: could not parse placeholder layer bullet\n  %s\n"
            ."Expected: - `<pattern with one or two {Name} segments>`",
            $line,
            trim($text),
        ));
    }

    public static function placeholderCaptureAfterDoubleWildcard(int $line, string $text): self
    {
        return new self(sprintf(
            "line %d: `**` before `{Name}` is not supported (ambiguous which segment is captured)\n  %s",
            $line,
            trim($text),
        ));
    }
}
