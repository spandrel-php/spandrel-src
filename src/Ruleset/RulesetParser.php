<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Ruleset;

use Spandrel\Spandrel\Graph\DependencyKind;
use Spandrel\Spandrel\Graph\ElementKind;

/**
 * Parses the `## Layers` and `## Rules` sections of a ruleset markdown file
 * (`parse()`), or several merged into one ruleset (`parseAll()`). See
 * docs/README.md for the full grammar.
 *
 * @phpstan-type ExplicitDeclaration array{kind: 'explicit', name: string, patterns: string[], exceptTokens: string[], line: int}
 * @phpstan-type ExternalDeclaration array{kind: 'external', name: string, patterns: string[], exceptTokens: string[], line: int}
 * @phpstan-type GroupDeclaration array{kind: 'group', name: string, memberTokens: string[], exceptTokens: string[], line: int}
 * @phpstan-type LayerDeclaration ExplicitDeclaration|ExternalDeclaration|GroupDeclaration
 */
final class RulesetParser
{
    private const LAYER_NAME_SHAPE = '/^[A-Za-z][A-Za-z0-9_]*$/';

    private const EXPLICIT_LAYER_PATTERN = '/^-\s*\*\*([A-Za-z][A-Za-z0-9_]*)\*\*:\s*(.+)$/';

    private const GROUP_LAYER_PATTERN = '/^-\s*\*\*([A-Za-z][A-Za-z0-9_]*)\*\*\s+groups\s+(.+)$/';

    private const EXTERNAL_LAYER_PATTERN = '/^-\s*\*\*([A-Za-z][A-Za-z0-9_]*)\*\*\s+matches\s+(.+)$/';

    private const PLACEHOLDER_BULLET_PATTERN = '/^-\s*`([^`]+)`\s*$/';

    private const CAPTURE_SEGMENT_SHAPE = '/^\{([A-Za-z][A-Za-z0-9_]*)\}$/';

    private const NULLARY_PATTERN = '/^-\s*(.+?)\s+(depends on nothing|may depend on anything)\s*$/';

    private const VERB_PATTERN = '/^-\s*(.+?)\s+(must not depend on|may only depend on)\s+(.+)$/';

    private const KIND_SCOPED_BARE_PATTERN = '/^-\s*(.+?)\s+must not instantiate objects\s*$/';

    private const KIND_SCOPED_VERB_PATTERN = '/^-\s*(.+?)\s+('
        .'may only call functions defined in|must not call functions defined in|'
        .'may only instantiate objects from|must not instantiate objects from|'
        .'may only throw|must not throw'
        .')\s+(.+)$/';

    // `subtypes of` is matched first so it consumes the whole "subtypes of `X`" span,
    // not just the backtick'd FQCN half.
    private const UNSCOPED_OBJECT_TOKEN_PATTERN = '/\bsubtypes of\s+`([^`]+)`|`([^`]+)`/';
    private const KIND_SCOPED_OBJECT_TOKEN_PATTERN = '/\bsubtypes of\s+`([^`]+)`|`([^`]+)`|\b(core functions|core classes)\b/';

    // `## Meta` bullets are fixed, exactly-matched sentences — no synonyms.
    private const META_STRICT_ELEMENTS_SENTENCE = 'Any class not in a layer violates rules.';
    private const META_STRICT_PARSING_SENTENCE = 'A file that fails to parse violates rules.';
    private const META_STRICT_LAYERS_SENTENCE = 'Every layer must be used in a rule.';

    // Comma-separated list (optional Oxford "and"/"or") or bare "and"/"or".
    private const ELEMENT_KIND_SEPARATOR = '\s*,\s*(?:and\s+|or\s+)?|\s+(?:and|or)\s+';

    private const ELEMENT_KIND_FILTER_PATTERN = '/^\s*((?:classes|interfaces|traits|enums)'
        .'(?:(?:'.self::ELEMENT_KIND_SEPARATOR.')(?:classes|interfaces|traits|enums))*)\s+in\s+(.*)$/s';

    /** @var array<string, ElementKind> */
    private const ELEMENT_KIND_WORDS = [
        'classes' => ElementKind::ClassLike,
        'interfaces' => ElementKind::Interface,
        'traits' => ElementKind::Trait,
        'enums' => ElementKind::Enum,
    ];

    /**
     * @param \Spandrel\Spandrel\Graph\Element[] $elements Code Graph, used only to derive
     *                                                       `{Name}` placeholder layers
     */
    public function parse(string $markdown, array $elements = []): Ruleset
    {
        return $this->parseAll([['', $markdown]], $elements);
    }

    /**
     * Merges several files into one ruleset: layer names and rule modes must not
     * conflict across any of them, and a `group`/`except`/rule in one file may
     * reference a layer declared in another. Each file keeps its own line numbers;
     * a `RulesetParseException` gets its file path prepended only when there's more
     * than one file.
     *
     * @param array<int, array{0: string, 1: string}> $files [path, content] pairs
     * @param \Spandrel\Spandrel\Graph\Element[] $elements Code Graph, used only to derive
     *                                                       `{Name}` placeholder layers
     */
    public function parseAll(array $files, array $elements = []): Ruleset
    {
        $multiFile = count($files) > 1;

        /** @var array<string, true> $names */
        $names = [];
        $allDeclarations = [];
        $allPlaceholderTemplates = [];

        foreach ($files as [$path, $markdown]) {
            try {
                [$declarations, $placeholderTemplates] = $this->collectLayerDeclarations($markdown, $elements, $names);
            } catch (RulesetParseException $e) {
                throw $multiFile ? $this->withFileLabel($path, $e) : $e;
            }

            array_push($allDeclarations, ...$declarations);
            array_push($allPlaceholderTemplates, ...$placeholderTemplates);
        }

        $layers = $this->resolveLayers($allDeclarations, $names);

        $allRules = [];
        $allUnconstrained = [];
        /** @var array<string, array<string, string>> $subjectModes */
        $subjectModes = [];

        foreach ($files as [$path, $markdown]) {
            try {
                [$rules, $unconstrained] = $this->collectRules($markdown, $names, $subjectModes);
            } catch (RulesetParseException $e) {
                throw $multiFile ? $this->withFileLabel($path, $e) : $e;
            }

            array_push($allRules, ...$rules);
            array_push($allUnconstrained, ...$unconstrained);
        }

        $strictElements = false;
        $strictParsing = false;
        $strictLayers = false;

        foreach ($files as [$path, $markdown]) {
            try {
                $meta = $this->collectMeta($markdown);
            } catch (RulesetParseException $e) {
                throw $multiFile ? $this->withFileLabel($path, $e) : $e;
            }

            $strictElements = $strictElements || $meta->strictElements;
            $strictParsing = $strictParsing || $meta->strictParsing;
            $strictLayers = $strictLayers || $meta->strictLayers;
        }

        $meta = new RulesetMeta($strictElements, $strictParsing, $strictLayers);

        return new Ruleset($layers, $allRules, $allUnconstrained, $allPlaceholderTemplates, $meta);
    }

    private function collectMeta(string $markdown): RulesetMeta
    {
        $strictElements = false;
        $strictParsing = false;
        $strictLayers = false;

        foreach ($this->linesInSection($markdown, 'Meta') as $lineNumber => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || !str_starts_with($trimmed, '-')) {
                continue;
            }

            $sentence = trim(substr($trimmed, 1));

            match ($sentence) {
                self::META_STRICT_ELEMENTS_SENTENCE => $strictElements = true,
                self::META_STRICT_PARSING_SENTENCE => $strictParsing = true,
                self::META_STRICT_LAYERS_SENTENCE => $strictLayers = true,
                default => throw RulesetParseException::malformedMetaBullet($lineNumber, $line),
            };
        }

        return new RulesetMeta($strictElements, $strictParsing, $strictLayers);
    }

    private function withFileLabel(string $path, RulesetParseException $e): RulesetParseException
    {
        return new RulesetParseException($path.': '.$e->getMessage(), previous: $e);
    }

    /**
     * Resolving `except`/`groups` happens later, in `resolveLayers()`, once every
     * file's declarations are collected.
     *
     * @param \Spandrel\Spandrel\Graph\Element[] $elements
     * @param array<string, true> $names accumulates across files, by reference
     * @return array{0: array<int, LayerDeclaration>, 1: string[]}
     */
    private function collectLayerDeclarations(string $markdown, array $elements, array &$names): array
    {
        /** @var array<int, LayerDeclaration> $declarations ordered, one per bullet */
        $declarations = [];
        /** @var string[] $placeholderTemplates */
        $placeholderTemplates = [];

        foreach ($this->linesInSection($markdown, 'Layers') as $lineNumber => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || !str_starts_with($trimmed, '-')) {
                continue;
            }

            if (preg_match(self::EXPLICIT_LAYER_PATTERN, $trimmed, $matches) === 1) {
                [$name, $rest] = [$matches[1], $matches[2]];
                $this->assertUndeclared($name, $names, $lineNumber);
                $names[$name] = true;

                [$patternText, $exceptText] = $this->splitExcept($rest);
                $patterns = $this->extractNames($patternText);

                if ($patterns === []) {
                    throw RulesetParseException::malformedLayerBullet($lineNumber, $line);
                }

                $declarations[] = [
                    'kind' => 'explicit',
                    'name' => $name,
                    'patterns' => $patterns,
                    'exceptTokens' => $exceptText !== null ? $this->extractNames($exceptText) : [],
                    'line' => $lineNumber,
                ];

                continue;
            }

            if (preg_match(self::GROUP_LAYER_PATTERN, $trimmed, $matches) === 1) {
                [$name, $rest] = [$matches[1], $matches[2]];
                $this->assertUndeclared($name, $names, $lineNumber);
                $names[$name] = true;

                [$memberText, $exceptText] = $this->splitExcept($rest);
                $memberTokens = $this->extractNames($memberText);

                if ($memberTokens === []) {
                    throw RulesetParseException::malformedLayerBullet($lineNumber, $line);
                }

                $declarations[] = [
                    'kind' => 'group',
                    'name' => $name,
                    'memberTokens' => $memberTokens,
                    'exceptTokens' => $exceptText !== null ? $this->extractNames($exceptText) : [],
                    'line' => $lineNumber,
                ];

                continue;
            }

            if (preg_match(self::EXTERNAL_LAYER_PATTERN, $trimmed, $matches) === 1) {
                [$name, $rest] = [$matches[1], $matches[2]];
                $this->assertUndeclared($name, $names, $lineNumber);
                $names[$name] = true;

                [$patternText, $exceptText] = $this->splitExcept($rest);
                $patterns = $this->extractNames($patternText);

                if ($patterns === []) {
                    throw RulesetParseException::malformedLayerBullet($lineNumber, $line);
                }

                $declarations[] = [
                    'kind' => 'external',
                    'name' => $name,
                    'patterns' => $patterns,
                    'exceptTokens' => $exceptText !== null ? $this->extractNames($exceptText) : [],
                    'line' => $lineNumber,
                ];

                continue;
            }

            if (preg_match(self::PLACEHOLDER_BULLET_PATTERN, $trimmed, $matches) === 1) {
                $placeholderTemplates[] = $matches[1];
                array_push($declarations, ...$this->derivePlaceholderLayers($matches[1], $elements, $lineNumber, $names));

                continue;
            }

            throw RulesetParseException::malformedLayerBullet($lineNumber, $line);
        }

        return [$declarations, $placeholderTemplates];
    }

    /**
     * One or two `{Name}` captures per template. A single capture derives one leaf
     * layer per distinct namespace segment. Two captures derive a leaf per distinct
     * combination (`_`-joined, e.g. `Billing_Domain`), plus a group layer per
     * distinct value on each axis, so a rule can still target a whole axis
     * (`` `Domain` must not depend on `Infrastructure` `` across every module).
     *
     * @param \Spandrel\Spandrel\Graph\Element[] $elements
     * @param array<string, true> $names
     * @return array<int, LayerDeclaration> synthetic 'explicit' (one per distinct
     *                                            combination) and, for two captures,
     *                                            'group' declarations (one per distinct
     *                                            value on each axis)
     */
    private function derivePlaceholderLayers(string $template, array $elements, int $lineNumber, array &$names): array
    {
        $templateSegments = explode('\\', ltrim($template, '\\'));
        $captureIndexes = [];

        foreach ($templateSegments as $i => $segment) {
            if (preg_match(self::CAPTURE_SEGMENT_SHAPE, $segment) === 1) {
                $captureIndexes[] = $i;
            }
        }

        if ($captureIndexes === [] || count($captureIndexes) > 2) {
            throw RulesetParseException::malformedPlaceholderBullet($lineNumber, $template);
        }

        $lastCaptureIndex = $captureIndexes[count($captureIndexes) - 1];

        for ($i = 0; $i < $lastCaptureIndex; $i++) {
            if ($templateSegments[$i] === '**') {
                throw RulesetParseException::placeholderCaptureAfterDoubleWildcard($lineNumber, $template);
            }
        }

        /** @var array<string, string[]> $combosByKey NUL-joined dedup key => ordered captured values */
        $combosByKey = [];

        foreach ($elements as $element) {
            $fqcnSegments = explode('\\', ltrim($element->fqcn, '\\'));

            if (count($fqcnSegments) <= $lastCaptureIndex) {
                continue;
            }

            $captured = [];
            $prefixMatches = true;

            for ($i = 0; $i <= $lastCaptureIndex; $i++) {
                if (in_array($i, $captureIndexes, true)) {
                    $captured[] = $fqcnSegments[$i];

                    continue;
                }

                if ($templateSegments[$i] !== '*' && $templateSegments[$i] !== $fqcnSegments[$i]) {
                    $prefixMatches = false;

                    break;
                }
            }

            if ($prefixMatches) {
                $combosByKey[implode("\0", $captured)] = $captured;
            }
        }

        $declarations = [];

        /** @var array<int, array<string, string[]>> $membersByAxisValue axis index => value => leaf names */
        $membersByAxisValue = [];

        foreach ($combosByKey as $captured) {
            $leafName = implode('_', $captured);
            $this->assertUndeclared($leafName, $names, $lineNumber);
            $names[$leafName] = true;

            $substituted = $templateSegments;

            foreach ($captureIndexes as $axis => $idx) {
                $substituted[$idx] = $captured[$axis];
            }

            $declarations[] = [
                'kind' => 'explicit',
                'name' => $leafName,
                'patterns' => [implode('\\', $substituted)],
                'exceptTokens' => [],
                'line' => $lineNumber,
            ];

            foreach ($captureIndexes as $axis => $idx) {
                $membersByAxisValue[$axis][$captured[$axis]][] = $leafName;
            }
        }

        if (count($captureIndexes) > 1) {
            foreach ($membersByAxisValue as $groupsForAxis) {
                foreach ($groupsForAxis as $axisValue => $memberNames) {
                    $this->assertUndeclared($axisValue, $names, $lineNumber);
                    $names[$axisValue] = true;

                    $declarations[] = [
                        'kind' => 'group',
                        'name' => $axisValue,
                        'memberTokens' => $memberNames,
                        'exceptTokens' => [],
                        'line' => $lineNumber,
                    ];
                }
            }
        }

        return $declarations;
    }

    /**
     * @param array<int, LayerDeclaration> $declarations
     * @param array<string, true> $names
     * @return Layer[]
     */
    private function resolveLayers(array $declarations, array $names): array
    {
        /** @var array<string, string[]> $rawPatternsByName explicit and external layers only — what `except` may reference */
        $rawPatternsByName = [];

        foreach ($declarations as $decl) {
            if ($decl['kind'] === 'explicit' || $decl['kind'] === 'external') {
                $rawPatternsByName[$decl['name']] = $decl['patterns'];
            }
        }

        $this->detectExceptCycles($declarations, $rawPatternsByName);

        /** @var array<string, Layer> $resolvedByName */
        $resolvedByName = [];

        foreach ($declarations as $decl) {
            if ($decl['kind'] !== 'explicit' && $decl['kind'] !== 'external') {
                continue;
            }

            $except = $this->resolveExceptTokens($decl['exceptTokens'], $rawPatternsByName, $decl['line']);
            $resolvedByName[$decl['name']] = new Layer($decl['name'], $decl['patterns'], $except, isExternal: $decl['kind'] === 'external');
        }

        /** @var array<string, GroupDeclaration> $groupsByName */
        $groupsByName = [];

        foreach ($declarations as $decl) {
            if ($decl['kind'] === 'group') {
                $groupsByName[$decl['name']] = $decl;
            }
        }

        $visiting = [];

        foreach (array_keys($groupsByName) as $name) {
            $this->resolveGroup($name, $groupsByName, $resolvedByName, $rawPatternsByName, $visiting, $names);
        }

        $layers = [];

        foreach ($declarations as $decl) {
            $layers[] = $resolvedByName[$decl['name']];
        }

        return $layers;
    }

    /**
     * A cycle in `except`-by-name references (`Y except Exception` / `Exception
     * except Y`) is almost certainly a mistake worth failing loudly on. Only
     * explicit and external layers can appear here — groups can't be the target
     * of an except-by-name reference (see `resolveExceptTokens()`).
     *
     * @param array<int, LayerDeclaration> $declarations
     * @param array<string, string[]> $rawPatternsByName
     */
    private function detectExceptCycles(array $declarations, array $rawPatternsByName): void
    {
        /** @var array<string, string[]> $edges layer name => except-referenced layer names */
        $edges = [];
        /** @var array<string, int> $lineByName */
        $lineByName = [];

        foreach ($declarations as $decl) {
            if ($decl['kind'] !== 'explicit' && $decl['kind'] !== 'external') {
                continue;
            }

            $lineByName[$decl['name']] = $decl['line'];
            $edges[$decl['name']] = array_values(array_filter(
                $decl['exceptTokens'],
                static fn (string $token): bool => preg_match(self::LAYER_NAME_SHAPE, $token) === 1 && isset($rawPatternsByName[$token]),
            ));
        }

        /** @var array<string, bool> $state true = currently visiting, false = done */
        $state = [];

        foreach (array_keys($edges) as $name) {
            $this->walkExceptEdges($name, $edges, $lineByName, $state);
        }
    }

    /**
     * @param array<string, string[]> $edges
     * @param array<string, int> $lineByName
     * @param array<string, bool> $state
     */
    private function walkExceptEdges(string $name, array $edges, array $lineByName, array &$state): void
    {
        if (($state[$name] ?? null) === false) {
            return;
        }

        if (($state[$name] ?? null) === true) {
            throw RulesetParseException::exceptCycleDetected($lineByName[$name], $name);
        }

        $state[$name] = true;

        foreach ($edges[$name] as $next) {
            $this->walkExceptEdges($next, $edges, $lineByName, $state);
        }

        $state[$name] = false;
    }

    /**
     * @param string[] $tokens
     * @param array<string, string[]> $rawPatternsByName
     * @return string[]
     */
    private function resolveExceptTokens(array $tokens, array $rawPatternsByName, int $lineNumber): array
    {
        $patterns = [];

        foreach ($tokens as $token) {
            if (preg_match(self::LAYER_NAME_SHAPE, $token) === 1) {
                if (!isset($rawPatternsByName[$token])) {
                    throw RulesetParseException::unknownLayerInLayerExcept($lineNumber, $token);
                }

                array_push($patterns, ...$rawPatternsByName[$token]);
            } else {
                $patterns[] = $token;
            }
        }

        return $patterns;
    }

    /**
     * @param array<string, GroupDeclaration> $groupsByName
     * @param array<string, Layer> $resolvedByName
     * @param array<string, string[]> $rawPatternsByName
     * @param array<string, true> $visiting
     * @param array<string, true> $names
     */
    private function resolveGroup(
        string $name,
        array $groupsByName,
        array &$resolvedByName,
        array $rawPatternsByName,
        array &$visiting,
        array $names,
    ): Layer {
        if (isset($resolvedByName[$name])) {
            return $resolvedByName[$name];
        }

        if (isset($visiting[$name])) {
            throw RulesetParseException::groupCycleDetected($groupsByName[$name]['line'], $name);
        }

        $visiting[$name] = true;
        $decl = $groupsByName[$name];

        $members = [];
        $flattenedPatterns = [];

        foreach ($decl['memberTokens'] as $memberName) {
            if (isset($groupsByName[$memberName])) {
                $member = $this->resolveGroup($memberName, $groupsByName, $resolvedByName, $rawPatternsByName, $visiting, $names);
            } elseif (isset($resolvedByName[$memberName])) {
                $member = $resolvedByName[$memberName];
            } else {
                throw RulesetParseException::unknownLayerInGroup($decl['line'], $name, $memberName);
            }

            $members[] = $member;
            array_push($flattenedPatterns, ...$member->patterns);
        }

        unset($visiting[$name]);

        $except = $this->resolveExceptTokens($decl['exceptTokens'], $rawPatternsByName, $decl['line']);
        $layer = new Layer($name, $flattenedPatterns, $except, isGroup: true, members: $members);
        $resolvedByName[$name] = $layer;

        return $layer;
    }

    /**
     * @param array<string, true> $names declared layer names, from the Layers pass (all files)
     * @param array<string, array<string, string>> $subjectModes subject => scopeKey => mode;
     *                                                             accumulates across files, by reference
     * @return array{0: Rule[], 1: string[]}
     */
    private function collectRules(string $markdown, array $names, array &$subjectModes): array
    {
        $rules = [];
        /** @var array<string, true> $unconstrained subject name => declared `may depend on anything` */
        $unconstrained = [];

        foreach ($this->linesInSection($markdown, 'Rules') as $lineNumber => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || !str_starts_with($trimmed, '-')) {
                continue;
            }

            if (preg_match(self::NULLARY_PATTERN, $trimmed, $matches) === 1) {
                $subjects = $this->extractNames($matches[1]);

                if (count($subjects) !== 1) {
                    throw RulesetParseException::malformedRuleBullet($lineNumber, $line);
                }

                $subject = $subjects[0];
                $this->assertDeclared($subject, $names, $lineNumber);

                $mode = $matches[2] === 'depends on nothing' ? 'nothing' : 'anything';
                $this->recordMode($subjectModes, $subject, 'unscoped', $mode, $lineNumber);

                if ($mode === 'nothing') {
                    foreach (array_keys($names) as $otherLayer) {
                        if ($otherLayer !== $subject) {
                            $rules[] = new Rule($subject, RuleVerb::MustNotDependOn, $otherLayer);
                        }
                    }
                } else {
                    $unconstrained[$subject] = true;
                }

                continue;
            }

            if (preg_match(self::KIND_SCOPED_BARE_PATTERN, $trimmed, $matches) === 1) {
                [$subjectKinds, $subjectText] = $this->stripElementKindFilter($matches[1]);
                $subjects = $this->extractOperands($subjectText, $names, $lineNumber);

                if (count($subjects) !== 1) {
                    throw RulesetParseException::malformedRuleBullet($lineNumber, $line);
                }

                $subject = $subjects[0];
                $this->recordModeIfDeclaredLayer($subjectModes, $subject, DependencyKind::Instantiate->value, 'must-not', $lineNumber);

                $rules[] = new Rule($subject, RuleVerb::MustNotDependOn, null, DependencyKind::Instantiate, subjectElementKinds: $subjectKinds);

                continue;
            }

            if (preg_match(self::KIND_SCOPED_VERB_PATTERN, $trimmed, $matches) === 1) {
                [$subjectKinds, $subjectText] = $this->stripElementKindFilter($matches[1]);
                $subjects = $this->extractOperands($subjectText, $names, $lineNumber);
                [$kind, $verb] = $this->resolveKindScopedVerb($matches[2]);
                [$objects, $except, $objectKinds] = $this->extractObjectsWithExcept($matches[3], $kind, $names, $lineNumber);

                if ($subjects === [] || $objects === []) {
                    throw RulesetParseException::malformedRuleBullet($lineNumber, $line);
                }

                $mode = $verb === RuleVerb::MustNotDependOn ? 'must-not' : 'may-only';

                foreach ($subjects as $subject) {
                    $this->recordModeIfDeclaredLayer($subjectModes, $subject, $kind->value, $mode, $lineNumber);
                }

                foreach ($subjects as $subject) {
                    foreach ($objects as $object) {
                        $rules[] = new Rule($subject, $verb, $object, $kind, $except, $subjectKinds, $objectKinds);
                    }
                }

                continue;
            }

            if (preg_match(self::VERB_PATTERN, $trimmed, $matches) === 1) {
                [$subjectKinds, $subjectText] = $this->stripElementKindFilter($matches[1]);
                $subjects = $this->extractOperands($subjectText, $names, $lineNumber);
                $verbText = $matches[2];
                [$objects, $except, $objectKinds] = $this->extractObjectsWithExcept($matches[3], null, $names, $lineNumber);

                if ($subjects === [] || $objects === []) {
                    throw RulesetParseException::malformedRuleBullet($lineNumber, $line);
                }

                $verb = $verbText === 'must not depend on' ? RuleVerb::MustNotDependOn : RuleVerb::MayOnlyDependOn;
                $mode = $verb === RuleVerb::MustNotDependOn ? 'must-not' : 'may-only';

                foreach ($subjects as $subject) {
                    $this->recordModeIfDeclaredLayer($subjectModes, $subject, 'unscoped', $mode, $lineNumber);
                }

                foreach ($subjects as $subject) {
                    foreach ($objects as $object) {
                        $rules[] = new Rule($subject, $verb, $object, null, $except, $subjectKinds, $objectKinds);
                    }
                }

                continue;
            }

            throw RulesetParseException::malformedRuleBullet($lineNumber, $line);
        }

        return [$rules, array_keys($unconstrained)];
    }

    /**
     * @return array{0: DependencyKind, 1: RuleVerb}
     */
    private function resolveKindScopedVerb(string $phrase): array
    {
        return match ($phrase) {
            'may only call functions defined in' => [DependencyKind::Call, RuleVerb::MayOnlyDependOn],
            'must not call functions defined in' => [DependencyKind::Call, RuleVerb::MustNotDependOn],
            'may only instantiate objects from' => [DependencyKind::Instantiate, RuleVerb::MayOnlyDependOn],
            'must not instantiate objects from' => [DependencyKind::Instantiate, RuleVerb::MustNotDependOn],
            'may only throw' => [DependencyKind::Throw, RuleVerb::MayOnlyDependOn],
            'must not throw' => [DependencyKind::Throw, RuleVerb::MustNotDependOn],
            // Unreachable: $phrase always comes from KIND_SCOPED_VERB_PATTERN's alternation.
            default => throw new \LogicException("Unhandled kind-scoped verb phrase: {$phrase}"),
        };
    }

    /**
     * Object extraction shared by unscoped (`$kind === null`) and kind-scoped bullets.
     * Strips a leading element-kind filter, then an optional trailing `except`
     * clause; the base side may be a list, and so may the except side, but
     * `except` doesn't nest.
     *
     * @param array<string, true> $names
     * @return array{0: array<int, string|InlinePattern|ReservedTarget|TypeHierarchyTarget>, 1: array<int, string|InlinePattern|TypeHierarchyTarget>|null, 2: ElementKind[]|null}
     */
    private function extractObjectsWithExcept(string $text, ?DependencyKind $kind, array $names, int $lineNumber): array
    {
        [$elementKinds, $text] = $this->stripElementKindFilter($text);

        [$baseText, $exceptText] = $this->splitExcept($text);

        $objects = $kind === null
            ? $this->extractObjectOperands($baseText, null, $names, $lineNumber)
            : $this->extractKindScopedObjects($baseText, $kind, $names, $lineNumber);

        if ($exceptText === null) {
            return [$objects, null, $elementKinds];
        }

        // A second `except` inside the except text is a nesting attempt, not a list.
        if (preg_match('/\bexcept\b/', $exceptText) === 1) {
            throw RulesetParseException::exceptDoesNotNest($lineNumber, $exceptText);
        }

        $exceptOperands = $this->extractObjectOperands($exceptText, $kind, $names, $lineNumber);

        if ($exceptOperands === []) {
            throw RulesetParseException::malformedExceptClause($lineNumber, $exceptText);
        }

        if (count($objects) > 1) {
            throw RulesetParseException::exceptWithListNotSupported($lineNumber, $text);
        }

        return [$objects, $exceptOperands, $elementKinds];
    }

    /**
     * Strips a leading `` (classes|interfaces|traits|enums)(, ...)? in `` prefix.
     * Can't false-positive against an ordinary operand, which always starts with
     * a backtick rather than a bare kind keyword.
     *
     * @return array{0: ElementKind[]|null, 1: string}
     */
    private function stripElementKindFilter(string $text): array
    {
        if (preg_match(self::ELEMENT_KIND_FILTER_PATTERN, $text, $matches) !== 1) {
            return [null, $text];
        }

        $words = preg_split('/'.self::ELEMENT_KIND_SEPARATOR.'/', trim($matches[1])) ?: [];

        $kinds = array_values(array_filter(array_map(
            static fn (string $word): ?ElementKind => self::ELEMENT_KIND_WORDS[$word] ?? null,
            $words,
        )));

        return [$kinds, $matches[2]];
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitExcept(string $text): array
    {
        if (preg_match('/^(.*?)\bexcept\b(.*)$/s', $text, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [$text, null];
    }

    /**
     * @param array<string, true> $names
     * @return array<int, string|InlinePattern|ReservedTarget|TypeHierarchyTarget>
     */
    private function extractKindScopedObjects(string $text, DependencyKind $kind, array $names, int $lineNumber): array
    {
        if (preg_match_all(self::KIND_SCOPED_OBJECT_TOKEN_PATTERN, $text, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $objects = [];

        foreach ($matches as $match) {
            $subtypeFqcn = $match[1] ?? '';

            if ($subtypeFqcn !== '') {
                if ($kind === DependencyKind::Call) {
                    throw RulesetParseException::typeHierarchyTargetWrongScope($lineNumber);
                }

                $objects[] = new TypeHierarchyTarget($subtypeFqcn);

                continue;
            }

            $token = $match[2] ?? '';

            if ($token !== '') {
                $objects[] = $this->classifyOperand($token, $names, $lineNumber);

                continue;
            }

            $reservedText = $match[3] ?? '';

            if ($reservedText === 'core functions') {
                if ($kind !== DependencyKind::Call) {
                    throw RulesetParseException::reservedTargetWrongScope($lineNumber, 'core functions', 'call-scoped');
                }

                $objects[] = ReservedTarget::CoreFunctions;

                continue;
            }

            if ($kind !== DependencyKind::Instantiate && $kind !== DependencyKind::Throw) {
                throw RulesetParseException::reservedTargetWrongScope($lineNumber, 'core classes', 'instantiate- or throw-scoped');
            }

            $objects[] = ReservedTarget::CoreClasses;
        }

        return $objects;
    }

    /**
     * Object-position-only operand extraction: a plain backtick'd operand, or a
     * `subtypes of `X`` type-hierarchy target. Used for the unscoped object list
     * and for every object list's `except` side; subject extraction
     * (`extractOperands()`) doesn't support this operand form.
     *
     * `subtypes of` is rejected on a call-scoped rule: functions have no type
     * hierarchy, so the rule could never match anything.
     *
     * @param array<string, true> $names
     * @return array<int, string|InlinePattern|TypeHierarchyTarget>
     */
    private function extractObjectOperands(string $text, ?DependencyKind $kind, array $names, int $lineNumber): array
    {
        if (preg_match_all(self::UNSCOPED_OBJECT_TOKEN_PATTERN, $text, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $objects = [];

        foreach ($matches as $match) {
            $subtypeFqcn = $match[1] ?? '';

            if ($subtypeFqcn !== '') {
                if ($kind === DependencyKind::Call) {
                    throw RulesetParseException::typeHierarchyTargetWrongScope($lineNumber);
                }

                $objects[] = new TypeHierarchyTarget($subtypeFqcn);

                continue;
            }

            $objects[] = $this->classifyOperand($match[2] ?? '', $names, $lineNumber);
        }

        return $objects;
    }

    /**
     * @param array<string, true> $names
     * @return array<int, string|InlinePattern>
     */
    private function extractOperands(string $text, array $names, int $lineNumber): array
    {
        if (preg_match_all('/`([^`]+)`/', $text, $matches) === 0) {
            return [];
        }

        return array_map(
            fn (string $token): string|InlinePattern => $this->classifyOperand($token, $names, $lineNumber),
            $matches[1],
        );
    }

    /**
     * @param array<string, true> $names
     */
    private function classifyOperand(string $token, array $names, int $lineNumber): string|InlinePattern
    {
        if (preg_match(self::LAYER_NAME_SHAPE, $token) !== 1) {
            return new InlinePattern($token);
        }

        $this->assertDeclared($token, $names, $lineNumber);

        return $token;
    }

    /**
     * @return string[]
     */
    private function extractNames(string $text): array
    {
        if (preg_match_all('/`([^`]+)`/', $text, $matches) === 0) {
            return [];
        }

        return $matches[1];
    }

    /**
     * @param array<string, true> $names
     */
    private function assertDeclared(string $name, array $names, int $lineNumber): void
    {
        if (!isset($names[$name])) {
            throw RulesetParseException::unknownLayerInRule($lineNumber, $name);
        }
    }

    /**
     * @param array<string, true> $names
     */
    private function assertUndeclared(string $name, array $names, int $lineNumber): void
    {
        if (isset($names[$name])) {
            throw RulesetParseException::duplicateLayerName($lineNumber, $name);
        }
    }

    /**
     * @param array<string, array<string, string>> $subjectModes
     */
    private function recordModeIfDeclaredLayer(array &$subjectModes, string|InlinePattern $subject, string $scopeKey, string $mode, int $lineNumber): void
    {
        if (is_string($subject)) {
            $this->recordMode($subjectModes, $subject, $scopeKey, $mode, $lineNumber);
        }
    }

    /**
     * @param array<string, array<string, string>> $subjectModes
     */
    private function recordMode(array &$subjectModes, string $subject, string $scopeKey, string $mode, int $lineNumber): void
    {
        if (isset($subjectModes[$subject][$scopeKey]) && $subjectModes[$subject][$scopeKey] !== $mode) {
            throw RulesetParseException::conflictingRuleMode($lineNumber, $subject);
        }

        $subjectModes[$subject][$scopeKey] = $mode;
    }

    /**
     * @return iterable<int, string>
     */
    private function linesInSection(string $markdown, string $heading): iterable
    {
        $inSection = false;

        foreach (explode("\n", $markdown) as $index => $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $headingMatch) === 1) {
                $inSection = trim($headingMatch[1]) === $heading;

                continue;
            }

            if ($inSection) {
                yield $index + 1 => $line;
            }
        }
    }
}
