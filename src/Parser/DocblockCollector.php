<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Parser;

use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Spandrel\Spandrel\Graph\Dependency;
use Spandrel\Spandrel\Graph\DependencyKind;

/**
 * Resolves types plain type-hints miss (`@param SomeClass[]`, `@return
 * Collection<SomeClass>`) into additional `Dependency` edges, reusing
 * `DependencyCollector`'s own `DependencyKind` values — a docblock-derived
 * edge just *is* that kind of edge, the same as a native one.
 *
 * Scoped identically to `DependencyCollector`'s native handling: only
 * `@param`/`@return` inside a named class-like's methods, and `@var` on
 * its properties. A free function's docblock is skipped too, matching
 * `DependencyCollector` dropping its native types there for the same
 * reason (empty `classStack`).
 */
final class DocblockCollector extends NodeVisitorAbstract
{
    /** @var string[] lowercase; not exhaustive — a miss just produces a Dependency with no matching Element, already silently dropped by the Rule Engine */
    private const BUILTIN_TYPES = [
        'array', 'array-key', 'bool', 'boolean', 'callable', 'class-string',
        'double', 'false', 'float', 'int', 'integer', 'iterable', 'list',
        'mixed', 'negative-int', 'never', 'non-empty-array', 'non-empty-list',
        'non-empty-string', 'non-falsy-string', 'null', 'numeric',
        'numeric-string', 'object', 'positive-int', 'resource', 'scalar',
        'string', 'true', 'void',
    ];

    private const RESERVED_NAMES = ['self', 'parent', 'static'];

    /** @var Dependency[] */
    private array $dependencies = [];

    /** @var array<int, string|null> enclosing class-like FQCN per nesting level; null for anonymous classes */
    private array $classStack = [];

    private readonly Lexer $lexer;
    private readonly PhpDocParser $phpDocParser;

    public function __construct(
        private readonly string $file,
        private readonly ColumnCalculator $columns,
        private readonly NameContext $nameContext,
    ) {
        $config = new ParserConfig(usedAttributes: []);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classStack[] = $node->namespacedName instanceof Node\Name
                ? $node->namespacedName->toString()
                : null;

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $doc = $this->parseDocblock($node);

            if ($doc !== null) {
                foreach ($doc->getReturnTagValues() as $tag) {
                    $this->addAllFromType($tag->type, DependencyKind::ReturnType, $node);
                }

                foreach ($doc->getParamTagValues() as $tag) {
                    $this->addAllFromType($tag->type, DependencyKind::ParamType, $node);
                }
            }

            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            $doc = $this->parseDocblock($node);

            if ($doc !== null) {
                foreach ($doc->getVarTagValues() as $tag) {
                    $this->addAllFromType($tag->type, DependencyKind::PropertyType, $node);
                }
            }

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
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

    private function parseDocblock(Node $node): ?PhpDocNode
    {
        if ($this->classStack === [] || $this->classStack[count($this->classStack) - 1] === null) {
            return null;
        }

        $comment = $node->getDocComment();

        if ($comment === null) {
            return null;
        }

        try {
            $tokens = new TokenIterator($this->lexer->tokenize($comment->getText()));

            return $this->phpDocParser->parse($tokens);
        } catch (ParserException) {
            return null;
        }
    }

    private function addAllFromType(TypeNode $type, DependencyKind $kind, Node $location): void
    {
        $from = $this->classStack[count($this->classStack) - 1];

        if ($from === null) {
            return;
        }

        foreach ($this->flattenType($type) as $rawName) {
            $this->add($from, $rawName, $kind, $location);
        }
    }

    /**
     * @return string[]
     */
    private function flattenType(TypeNode $type): array
    {
        if ($type instanceof IdentifierTypeNode) {
            return [$type->name];
        }

        if ($type instanceof ArrayTypeNode || $type instanceof NullableTypeNode) {
            return $this->flattenType($type->type);
        }

        if ($type instanceof UnionTypeNode || $type instanceof IntersectionTypeNode) {
            $names = [];

            foreach ($type->types as $inner) {
                $names = [...$names, ...$this->flattenType($inner)];
            }

            return $names;
        }

        if ($type instanceof GenericTypeNode) {
            $names = $this->flattenType($type->type);

            foreach ($type->genericTypes as $inner) {
                $names = [...$names, ...$this->flattenType($inner)];
            }

            return $names;
        }

        return [];
    }

    private function add(string $from, string $rawName, DependencyKind $kind, Node $location): void
    {
        if (in_array(strtolower($rawName), self::BUILTIN_TYPES, true)) {
            return;
        }

        if (in_array(strtolower($rawName), self::RESERVED_NAMES, true)) {
            return;
        }

        $name = str_starts_with($rawName, '\\')
            ? new Node\Name\FullyQualified(substr($rawName, 1))
            : new Node\Name($rawName);
        $to = $this->nameContext->getResolvedClassName($name)->toString();

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
