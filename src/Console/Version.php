<?php

declare(strict_types=1);

namespace Spandrel\Spandrel\Console;

final class Version
{
    /**
     * Replaced with the tag/commit `box compile` was run against — see
     * `git-version` in box.json, which names this constant as the
     * replacement target; Box then substitutes any literal occurrence of
     * `@<that name>@` it finds, which is why the placeholder below has to
     * spell out its own fully-qualified name. Left as the placeholder when
     * running from source (`php bin/spandrel.php`) rather than the
     * compiled PHAR.
     */
    public const string GIT_VERSION = '@Spandrel\Spandrel\Console\Version::GIT_VERSION@';

    public static function current(): string
    {
        // Box's replacement is a blind string substitution of the token
        // above wherever it appears — comparing against a copy of that same
        // token here would get rewritten right along with it. Checking for
        // the leading "@" survives the substitution instead: a real git
        // describe/hash never starts with one.
        return str_starts_with(self::GIT_VERSION, '@') ? 'dev' : self::GIT_VERSION;
    }
}
