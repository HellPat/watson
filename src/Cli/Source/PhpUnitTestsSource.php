<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;
use Watson\Core\Discovery\PhpUnitCollector;

/**
 * AST scan of `tests/` for `PHPUnit\Framework\TestCase` subclasses.
 */
final class PhpUnitTestsSource implements EntrypointSource
{
    public function name(): string
    {
        return 'phpunit.tests';
    }

    public function canHandle(Project $project): bool
    {
        return is_dir($project->rootPath . '/tests');
    }

    public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
    {
        if ($opts->wantsRoutesOnly()) {
            return [];
        }
        return PhpUnitCollector::collect($project->rootPath . '/tests', $reflector);
    }
}
