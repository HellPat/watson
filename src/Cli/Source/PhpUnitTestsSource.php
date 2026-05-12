<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;
use Watson\Core\Discovery\PhpUnitCollector;

/**
 * AST scan for `PHPUnit\Framework\TestCase` subclasses across every
 * directory the project declares under composer's `autoload-dev.psr-4`
 * section.
 *
 * Test classes are not always rooted at `/tests` — monorepos and
 * SDK-style packages frequently keep them under `utils/Foo/tests`,
 * `lib/Tests/`, etc. — but they're invariably declared in
 * `autoload-dev`. Production `autoload.psr-4` roots are intentionally
 * skipped: walking them just to look for `TestCase` subclasses forces
 * Better Reflection to parse every production class and chase its
 * inheritance chain. On Laravel-sized `app/` trees that's a
 * minutes-long operation with zero useful output.
 *
 * Inheritance is verified via Better Reflection — a subclass of an
 * intermediate `AbstractTestCase` still counts.
 */
final class PhpUnitTestsSource implements EntrypointSource
{
    public function name(): string
    {
        return 'phpunit.tests';
    }

    public function canHandle(Project $project): bool
    {
        return ProjectComposer::isInstalled($project, 'phpunit/phpunit');
    }

    public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
    {
        if ($opts->wantsRoutesOnly()) {
            return [];
        }
        return PhpUnitCollector::collect(ProjectComposer::psr4DevRoots($project));
    }
}
