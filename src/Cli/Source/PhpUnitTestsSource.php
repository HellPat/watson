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
 * section. The `autoload-dev` restriction is deliberate: monorepos and
 * SDK-style packages keep tests under `utils/Foo/tests`, `lib/Tests/`,
 * etc., but always under dev autoload — and production roots like
 * Laravel's `app/` shouldn't be walked just to look for tests.
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
        return PhpUnitCollector::collect(ProjectComposer::psr4Roots($project, devOnly: true));
    }
}
