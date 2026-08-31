<?php

declare(strict_types=1);

// Prefixes every vendored dependency (symfony/console, nikic/php-parser,
// phpstan/phpdoc-parser, ...) so spandrel.phar can never collide with a
// consuming project's own copy of the same library at a different version.
// Spandrel's own namespace is excluded so class names in output/backtraces
// stay `Spandrel\Spandrel\...` rather than a prefixed alias.
return [
    'prefix' => 'SpandrelVendor',
    'exclude-namespaces' => [
        'Spandrel\\Spandrel',
    ],
    'expose-global-constants' => true,
    'expose-global-classes' => true,
    'expose-global-functions' => true,
];
