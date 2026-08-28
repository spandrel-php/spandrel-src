<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Graph;

enum DependencyKind: string
{
    case Extends = 'extends';
    case Implements = 'implements';
    case UseTrait = 'use-trait';
    case ParamType = 'param-type';
    case PropertyType = 'property-type';
    case ReturnType = 'return-type';
    case Instantiate = 'new';
    case StaticCall = 'static-call';
    case Instanceof = 'instanceof';
    case Catch = 'catch';
    case Call = 'call';
    case Throw = 'throw';
    case Attribute = 'attribute';
}
