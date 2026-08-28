<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Parser;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser as PhpParserInterface;
use PhpParser\ParserFactory;

final class Parser
{
    private readonly PhpParserInterface $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function parse(string $file, string $contents): ParseResult
    {
        $ast = $this->parser->parse($contents) ?? [];

        $traverser = new NodeTraverser();
        $nameResolver = new NameResolver();
        $traverser->addVisitor($nameResolver);

        $columns = new ColumnCalculator($contents);
        $classLikeCollector = new ClassLikeCollector($file, $columns);
        $functionCollector = new FunctionCollector($file, $columns);
        $dependencyCollector = new DependencyCollector($file, $columns);
        $docblockCollector = new DocblockCollector($file, $columns, $nameResolver->getNameContext());
        $traverser->addVisitor($classLikeCollector);
        $traverser->addVisitor($functionCollector);
        $traverser->addVisitor($dependencyCollector);
        $traverser->addVisitor($docblockCollector);

        $traverser->traverse($ast);

        $elements = [...$classLikeCollector->elements(), ...$functionCollector->elements()];
        $dependencies = [...$dependencyCollector->dependencies(), ...$docblockCollector->dependencies()];

        return new ParseResult($elements, $dependencies);
    }
}
