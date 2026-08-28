<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Graph\ElementKind;

final class Rule
{
    /**
     * @param array<string|InlinePattern|TypeHierarchyTarget>|null $objectExcept one or more
     *        excepted operands, subtracted from `$object`; `null` means no `except` clause
     *        (never an empty array)
     * @param ElementKind[]|null $subjectElementKinds
     * @param ElementKind[]|null $objectElementKinds
     */
    public function __construct(
        public readonly string|InlinePattern $subject,
        public readonly RuleVerb $verb,
        public readonly string|InlinePattern|ReservedTarget|TypeHierarchyTarget|null $object,
        public readonly ?DependencyKind $kindScope = null,
        public readonly ?array $objectExcept = null,
        public readonly ?array $subjectElementKinds = null,
        public readonly ?array $objectElementKinds = null,
    ) {
    }

    public function verbPhrase(): string
    {
        $mustNot = $this->verb === RuleVerb::MustNotDependOn;

        return match ($this->kindScope) {
            null => $this->verb->asPhrase(),
            DependencyKind::Call => $mustNot ? 'must not call functions defined in' : 'may only call functions defined in',
            DependencyKind::Instantiate => match (true) {
                $this->object === null => 'must not instantiate objects',
                $mustNot => 'must not instantiate objects from',
                default => 'may only instantiate objects from',
            },
            DependencyKind::Throw => $mustNot ? 'must not throw' : 'may only throw',
            default => $this->verb->asPhrase(),
        };
    }

    public function describe(): string
    {
        $objectText = self::operandText($this->object, $this->objectElementKinds);

        if ($objectText !== null && $this->objectExcept !== null) {
            $objectText .= ' except '.self::describeExceptList($this->objectExcept);
        }

        $subjectText = self::operandText($this->subject, $this->subjectElementKinds);

        return $objectText === null
            ? sprintf('%s %s', $subjectText, $this->verbPhrase())
            : sprintf('%s %s %s', $subjectText, $this->verbPhrase(), $objectText);
    }

    /**
     * @param ElementKind[]|null $elementKinds
     */
    public static function operandText(string|InlinePattern|ReservedTarget|TypeHierarchyTarget|null $operand, ?array $elementKinds = null): ?string
    {
        $text = match (true) {
            $operand === null => null,
            $operand === ReservedTarget::CoreFunctions => 'core functions',
            $operand === ReservedTarget::CoreClasses => 'core classes',
            $operand instanceof TypeHierarchyTarget => "subtypes of `{$operand->fqcn}`",
            $operand instanceof InlinePattern => "`{$operand->pattern}`",
            default => "`{$operand}`",
        };

        if ($text === null || $elementKinds === null) {
            return $text;
        }

        return sprintf('%s in %s', self::describeKindList($elementKinds), $text);
    }

    /**
     * @param ElementKind[] $kinds
     */
    private static function describeKindList(array $kinds): string
    {
        return self::joinWithAnd(array_map(static fn (ElementKind $kind): string => match ($kind) {
            ElementKind::ClassLike => 'classes',
            ElementKind::Interface => 'interfaces',
            ElementKind::Trait => 'traits',
            ElementKind::Enum => 'enums',
            ElementKind::Function => throw new \LogicException('functions cannot appear in an element-kind filter'),
        }, $kinds));
    }

    /**
     * @param array<string|InlinePattern|TypeHierarchyTarget> $operands
     */
    private static function describeExceptList(array $operands): string
    {
        return self::joinWithAnd(array_map(
            static fn (string|InlinePattern|TypeHierarchyTarget $operand): string => (string) self::operandText($operand),
            $operands,
        ));
    }

    /**
     * @param string[] $words
     */
    private static function joinWithAnd(array $words): string
    {
        $count = count($words);

        if ($count === 1) {
            return $words[0];
        }

        $last = array_pop($words);

        return implode(', ', $words).($count === 2 ? ' and ' : ', and ').$last;
    }
}
