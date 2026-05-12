<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;
use Watson\Core\Discovery\PhpUnitCollector;

/**
 * AST scan for `PHPUnit\Framework\TestCase` subclasses across every
 * directory the project declares under composer's `autoload` /
 * `autoload-dev` PSR-4 sections.
 *
 * Test classes are not always rooted at `/tests` — monorepos and
 * SDK-style packages frequently keep them next to the source they
 * exercise, or under `utils/`, `lib/Tests/`, etc. The PSR-4 roots are
 * the authoritative answer; everything the project declares as
 * autoloaded is fair game for an AST scan.
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
        return PhpUnitCollector::collect(ProjectComposer::psr4Roots($project), $reflector);
    }
}
