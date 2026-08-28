<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\RuleEngine;

use Spandrel\Spandrel\Graph\CoreSymbols;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\Element;
use Spandrel\Spandrel\Graph\ElementKind;
use Spandrel\Spandrel\Ruleset\InlinePattern;
use Spandrel\Spandrel\Ruleset\Layer;
use Spandrel\Spandrel\Ruleset\LayerResolution;
use Spandrel\Spandrel\Ruleset\PatternMatcher;
use Spandrel\Spandrel\Ruleset\ReservedTarget;
use Spandrel\Spandrel\Ruleset\Rule;
use Spandrel\Spandrel\Ruleset\Ruleset;
use Spandrel\Spandrel\Ruleset\RuleVerb;
use Spandrel\Spandrel\Ruleset\TypeHierarchyTarget;

/**
 * Walks every `Dependency` edge and reports the ones that break the `Ruleset`.
 *
 * Every rule is checked against every edge (O(rules × edges), not indexed):
 * a rule's subject can be an `InlinePattern`, which has no resolved layer
 * name to index by. Fine at this project's scale.
 *
 * A group operand matches via `Layer::containsLeaf()` — a name-based check
 * against the edge's resolved leaf layer — not via its patterns against the
 * raw FQCN, so vendor code with no resolved `Element` still can't satisfy a
 * may-only rule through a group. An external layer is the deliberate
 * exception: it has no `Element` behind it by design, so it matches like an
 * `InlinePattern` does, straight against the FQCN. `InlinePattern`/
 * `ReservedTarget` operands likewise match directly against the FQCN,
 * independent of layer resolution. The same-layer bypass (`fromLayer ===
 * toLayer`) only fires when both resolve to an equal declared leaf layer.
 *
 * A subject's matching rules split into the unscoped bucket and the bucket
 * for the edge's own kind; unscoped is checked first, since kind-scoped can
 * only narrow, never grant an exception to something unscoped forbids.
 * Within a bucket, rules are further partitioned by the literal subject
 * operand (a leaf's own rules kept separate from a containing group's),
 * since parse-time's "one verb per subject" guarantee only covers one
 * literal subject string, not a leaf and every group containing it.
 */
final class RuleEngine
{
    /**
     * @param Dependency[] $dependencies
     * @return Violation[]
     */
    public function evaluate(Ruleset $ruleset, LayerResolution $resolution, array $dependencies): array
    {
        /** @var array<string, Layer> $layersByName */
        $layersByName = [];

        foreach ($ruleset->layers as $layer) {
            $layersByName[$layer->name] = $layer;
        }

        /** @var array<string, Element> $elementsByFqcn every resolved Element, matched or not — for element-kind filters */
        $elementsByFqcn = [];

        foreach ($resolution->matches as $matched) {
            foreach ($matched as $element) {
                $elementsByFqcn[$element->fqcn] = $element;
            }
        }

        foreach ($resolution->unmatched as $element) {
            $elementsByFqcn[$element->fqcn] = $element;
        }

        $typeHierarchy = new TypeHierarchy($dependencies);

        $violations = [];

        foreach ($dependencies as $dependency) {
            $fromLayer = $resolution->layerOf($dependency->from);
            $toLayer = $resolution->layerOf($dependency->to);

            if ($fromLayer !== null && $fromLayer === $toLayer) {
                continue;
            }

            $subjectRules = array_values(array_filter(
                $ruleset->rules,
                fn (Rule $rule): bool => $this->matchesOperand($rule->subject, $dependency->from, $fromLayer, $layersByName, $rule->subjectElementKinds, $elementsByFqcn, $typeHierarchy),
            ));

            if ($subjectRules === []) {
                continue;
            }

            $violation = $this->evaluateDependency($dependency, $fromLayer, $toLayer, $subjectRules, $layersByName, $elementsByFqcn, $typeHierarchy);

            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        usort(
            $violations,
            static fn (Violation $a, Violation $b): int => [$a->file, $a->line] <=> [$b->file, $b->line],
        );

        return $violations;
    }

    /**
     * @param Rule[] $subjectRules every rule whose subject matched this edge
     * @param array<string, Layer> $layersByName
     * @param array<string, Element> $elementsByFqcn
     */
    private function evaluateDependency(Dependency $dependency, ?string $fromLayer, ?string $toLayer, array $subjectRules, array $layersByName, array $elementsByFqcn, TypeHierarchy $typeHierarchy): ?Violation
    {
        $unscoped = array_values(array_filter($subjectRules, static fn (Rule $r): bool => $r->kindScope === null));

        foreach ($this->partitionBySubject($unscoped) as $cluster) {
            $violation = $this->evaluateEdgeAgainstGroup($dependency, $fromLayer, $toLayer, $cluster, $layersByName, $elementsByFqcn, $typeHierarchy);

            if ($violation !== null) {
                return $violation;
            }
        }

        $kindScoped = array_values(array_filter($subjectRules, static fn (Rule $r): bool => $r->kindScope === $dependency->kind));

        foreach ($this->partitionBySubject($kindScoped) as $cluster) {
            $violation = $this->evaluateEdgeAgainstGroup($dependency, $fromLayer, $toLayer, $cluster, $layersByName, $elementsByFqcn, $typeHierarchy);

            if ($violation !== null) {
                return $violation;
            }
        }

        return null;
    }

    /**
     * Splits a rule list into clusters sharing the same literal subject operand.
     *
     * @param Rule[] $rules
     * @return array<string, Rule[]>
     */
    private function partitionBySubject(array $rules): array
    {
        $clusters = [];

        foreach ($rules as $rule) {
            $key = is_string($rule->subject) ? 's:'.$rule->subject : 'p:'.$rule->subject->pattern;
            $clusters[$key][] = $rule;
        }

        return $clusters;
    }

    /**
     * @param Rule[] $group all sharing one subject-match, one scope, and one verb
     * @param array<string, Layer> $layersByName
     * @param array<string, Element> $elementsByFqcn
     */
    private function evaluateEdgeAgainstGroup(Dependency $dependency, ?string $fromLayer, ?string $toLayer, array $group, array $layersByName, array $elementsByFqcn, TypeHierarchy $typeHierarchy): ?Violation
    {
        // An element with no layer (vendor code, a PHP built-in, ...) is "not covered by
        // the ruleset" and silently skipped — but only when every rule in the group can
        // only ever match via a resolved layer name. A bare null object, InlinePattern,
        // ReservedTarget, TypeHierarchyTarget, or external layer must still be evaluated,
        // since those match independently of layer coverage.
        if ($toLayer === null && $this->everyObjectRequiresAResolvedLayer($group, $layersByName)) {
            return null;
        }

        $verb = $group[0]->verb;
        $matched = $this->matchInGroup($group, $dependency, $toLayer, $layersByName, $elementsByFqcn, $typeHierarchy);
        $isViolation = $verb === RuleVerb::MustNotDependOn ? $matched !== null : $matched === null;

        if (!$isViolation) {
            return null;
        }

        $ruleText = $matched !== null ? $matched->describe() : $this->describeGroup($group);
        $fromLabel = $fromLayer !== null ? sprintf('%s (%s)', $dependency->from, $fromLayer) : $dependency->from;
        $toLabel = match (true) {
            $dependency->to === Dependency::UNRESOLVABLE => 'an unresolvable target',
            $toLayer !== null => sprintf('%s (%s)', $dependency->to, $toLayer),
            default => $dependency->to,
        };

        return new Violation(
            rule: $ruleText,
            fromElement: $dependency->from,
            toElement: $dependency->to,
            dependencyKind: $dependency->kind,
            file: $dependency->file,
            line: $dependency->line,
            message: sprintf('%s %s %s via %s', $fromLabel, $group[0]->verbPhrase(), $toLabel, $dependency->kind->value),
            column: $dependency->column,
            endLine: $dependency->endLine,
            endColumn: $dependency->endColumn,
        );
    }

    /**
     * @param Rule[] $group
     * @param array<string, Layer> $layersByName
     */
    private function everyObjectRequiresAResolvedLayer(array $group, array $layersByName): bool
    {
        foreach ($group as $rule) {
            if (!is_string($rule->object)) {
                return false;
            }

            if (isset($layersByName[$rule->object]) && $layersByName[$rule->object]->isExternal) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Rule[] $group
     * @param array<string, Layer> $layersByName
     * @param array<string, Element> $elementsByFqcn
     */
    private function matchInGroup(array $group, Dependency $dependency, ?string $toLayer, array $layersByName, array $elementsByFqcn, TypeHierarchy $typeHierarchy): ?Rule
    {
        foreach ($group as $rule) {
            if ($this->objectMatches($rule, $dependency, $toLayer, $layersByName, $elementsByFqcn, $typeHierarchy)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<string, Layer> $layersByName
     * @param array<string, Element> $elementsByFqcn
     */
    private function objectMatches(Rule $rule, Dependency $dependency, ?string $toLayer, array $layersByName, array $elementsByFqcn, TypeHierarchy $typeHierarchy): bool
    {
        if ($rule->object === null) {
            return true;
        }

        if (!$this->matchesOperand($rule->object, $dependency->to, $toLayer, $layersByName, $rule->objectElementKinds, $elementsByFqcn, $typeHierarchy)) {
            return false;
        }

        foreach ($rule->objectExcept ?? [] as $exceptOperand) {
            if ($this->matchesOperand($exceptOperand, $dependency->to, $toLayer, $layersByName, null, $elementsByFqcn, $typeHierarchy)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, Layer> $layersByName
     * @param ElementKind[]|null $kindFilter
     * @param array<string, Element> $elementsByFqcn
     */
    private function matchesOperand(string|InlinePattern|ReservedTarget|TypeHierarchyTarget $operand, string $fqcn, ?string $resolvedLayer, array $layersByName, ?array $kindFilter, array $elementsByFqcn, TypeHierarchy $typeHierarchy): bool
    {
        $matches = $this->matchesOperandIgnoringKind($operand, $fqcn, $resolvedLayer, $layersByName, $typeHierarchy);

        if (!$matches || $kindFilter === null) {
            return $matches;
        }

        $element = $elementsByFqcn[$fqcn] ?? null;

        return $element !== null && in_array($element->kind, $kindFilter, true);
    }

    /**
     * @param array<string, Layer> $layersByName
     */
    private function matchesOperandIgnoringKind(string|InlinePattern|ReservedTarget|TypeHierarchyTarget $operand, string $fqcn, ?string $resolvedLayer, array $layersByName, TypeHierarchy $typeHierarchy): bool
    {
        if ($operand instanceof TypeHierarchyTarget) {
            return $typeHierarchy->isSubtypeOf($fqcn, $operand->fqcn);
        }

        if ($operand instanceof ReservedTarget) {
            return $operand === ReservedTarget::CoreFunctions
                ? CoreSymbols::isCoreFunction($fqcn)
                : CoreSymbols::isCoreClass($fqcn);
        }

        if ($operand instanceof InlinePattern) {
            return PatternMatcher::matches($operand->pattern, $fqcn);
        }

        if (!isset($layersByName[$operand])) {
            return false;
        }

        $layer = $layersByName[$operand];

        if ($layer->isExternal) {
            return $layer->matches($fqcn);
        }

        if ($resolvedLayer === null) {
            return false;
        }

        // A group's own `except` isn't reflected by containsLeaf() — a pure name check —
        // so it's applied here directly against the raw FQCN.
        foreach ($layer->except as $pattern) {
            if (PatternMatcher::matches($pattern, $fqcn)) {
                return false;
            }
        }

        return $layer->containsLeaf($resolvedLayer);
    }

    /**
     * @param Rule[] $group
     */
    private function describeGroup(array $group): string
    {
        $objects = implode(', ', array_map(
            static fn (Rule $rule): string => (string) Rule::operandText($rule->object, $rule->objectElementKinds),
            $group,
        ));

        return sprintf('%s %s %s', Rule::operandText($group[0]->subject, $group[0]->subjectElementKinds), $group[0]->verbPhrase(), $objects);
    }
}
