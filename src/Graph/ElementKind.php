<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Graph;

enum ElementKind: string
{
    case ClassLike = 'class';
    case Interface = 'interface';
    case Trait = 'trait';
    case Enum = 'enum';
    case Function = 'function';
}
