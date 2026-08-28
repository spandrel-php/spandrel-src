<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Parser;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Spandrel\Spandrel\Graph\CoreSymbols;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\DependencyKind;

/**
 * Collects statically-resolvable dependency edges: extends, implements,
 * use-trait, param/property/return type-hints, new, static calls,
 * instanceof, catch, function calls, `throw`, and attribute usage.
 * Docblock-derived types (`@param`/`@return`/`@var`) are handled
 * separately by `DocblockCollector`.
 *
 * Every `FuncCall`/`Throw_` node produces exactly one `Call`/`Throw` edge,
 * never silently nothing, so a kind-scoped whitelist verb never mistakes
 * "we don't know what this called/threw" for "verified safe." A bare
 * unqualified call (`array_map(...)`) resolves as a core function when
 * `CoreSymbols::isCoreFunction()` says so (true for the overwhelming
 * majority of real code, despite PHP's own namespace-then-global runtime
 * fallback); otherwise, and for any dynamic call expression, it's
 * `Dependency::UNRESOLVABLE`. `throw new X()` resolves like `new`;
 * anything else thrown (`throw $e;`, `throw new $var()`, ...) is likewise
 * unresolvable.
 */
final class DependencyCollector extends NodeVisitorAbstract
{
    private const RESERVED_NAMES = ['self', 'parent', 'static'];

    /** @var Dependency[] */
    private array $dependencies = [];

    /** @var array<int, string|null> enclosing class-like FQCN per nesting level; null for anonymous classes */
    private array $classStack = [];

    public function __construct(
        private readonly string $file,
        private readonly ColumnCalculator $columns,
    ) {
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classStack[] = $node->namespacedName instanceof Node\Name
                ? $node->namespacedName->toString()
                : null;

            if ($node instanceof Node\Stmt\Class_ && $node->extends instanceof Node\Name) {
                $this->addAll([$node->extends], DependencyKind::Extends, $node);
            }

            if ($node instanceof Node\Stmt\Interface_) {
                $this->addAll($node->extends, DependencyKind::Extends, $node);
            }

            if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_) {
                $this->addAll($node->implements, DependencyKind::Implements, $node);
            }

            return null;
        }

        if ($node instanceof Node\Stmt\TraitUse) {
            $this->addAll($node->traits, DependencyKind::UseTrait, $node);

            return null;
        }

        if ($node instanceof Node\Param) {
            $this->addTypeNames($node->type, DependencyKind::ParamType, $node);

            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            $this->addTypeNames($node->type, DependencyKind::PropertyType, $node);

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->addTypeNames($node->returnType, DependencyKind::ReturnType, $node);

            return null;
        }

        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $this->addAll([$node->class], DependencyKind::Instantiate, $node);

            return null;
        }

        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
            $this->addAll([$node->class], DependencyKind::StaticCall, $node);

            return null;
        }

        if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
            $this->addAll([$node->class], DependencyKind::Instanceof, $node);

            return null;
        }

        if ($node instanceof Node\Stmt\Catch_) {
            $this->addAll($node->types, DependencyKind::Catch, $node);

            return null;
        }

        if ($node instanceof Node\AttributeGroup) {
            // Each Attribute gets its own location, since one group can bundle several.
            foreach ($node->attrs as $attribute) {
                $this->add($attribute->name, DependencyKind::Attribute, $attribute);
            }

            return null;
        }

        if ($node instanceof Node\Expr\FuncCall) {
            if ($node->name instanceof Node\Name\FullyQualified) {
                $this->addAll([$node->name], DependencyKind::Call, $node);
            } elseif ($node->name instanceof Node\Name && CoreSymbols::isCoreFunction($node->name->toString())) {
                $this->addAll([$node->name], DependencyKind::Call, $node);
            } else {
                $this->addUnresolvable(DependencyKind::Call, $node);
            }

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        // Handled on leave, not enter: NameResolver only resolves the nested `new`
        // expression when the traverser visits it as its own step, after this node's enter.
        if ($node instanceof Node\Expr\Throw_) {
            if ($node->expr instanceof Node\Expr\New_ && $node->expr->class instanceof Node\Name) {
                $this->addAll([$node->expr->class], DependencyKind::Throw, $node);
            } else {
                $this->addUnresolvable(DependencyKind::Throw, $node);
            }
        }

        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classStack);
        }

        return null;
    }

    /**
     * @return Dependency[]
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * @param Node\Name[] $names
     */
    private function addAll(array $names, DependencyKind $kind, Node $location): void
    {
        foreach ($names as $name) {
            $this->add($name, $kind, $location);
        }
    }

    private function addTypeNames(?Node $type, DependencyKind $kind, Node $location): void
    {
        foreach ($this->flattenType($type) as $name) {
            $this->add($name, $kind, $location);
        }
    }

    /**
     * @return Node\Name[]
     */
    private function flattenType(?Node $type): array
    {
        if ($type instanceof Node\Name) {
            return [$type];
        }

        if ($type instanceof Node\NullableType) {
            return $this->flattenType($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $names = [];

            foreach ($type->types as $inner) {
                $names = [...$names, ...$this->flattenType($inner)];
            }

            return $names;
        }

        return [];
    }

    private function add(Node\Name $name, DependencyKind $kind, Node $location): void
    {
        $to = $name->toString();

        if (in_array(strtolower($to), self::RESERVED_NAMES, true)) {
            return;
        }

        $this->push($to, $kind, $location);
    }

    private function addUnresolvable(DependencyKind $kind, Node $location): void
    {
        $this->push(Dependency::UNRESOLVABLE, $kind, $location);
    }

    private function push(string $to, DependencyKind $kind, Node $location): void
    {
        if ($this->classStack === []) {
            return;
        }

        $from = $this->classStack[count($this->classStack) - 1];

        if ($from === null) {
            return;
        }

        $this->dependencies[] = new Dependency(
            $from,
            $to,
            $kind,
            $this->file,
            $location->getLine(),
            $this->columns->columnAt($location->getStartFilePos()),
            $location->getEndLine(),
            $this->columns->columnAt($location->getEndFilePos()),
        );
    }
}
