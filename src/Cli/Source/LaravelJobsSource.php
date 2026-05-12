<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;
use Watson\Core\Discovery\JobCollector;

/**
 * AST scan of `app/Jobs/` for `Illuminate\Contracts\Queue\ShouldQueue`
 * implementations.
 */
final class LaravelJobsSource implements EntrypointSource
{
    public function name(): string
    {
        return 'laravel.jobs';
    }

    public function canHandle(Project $project): bool
    {
        return is_dir($project->rootPath . '/app/Jobs')
            && ProjectComposer::isInstalled($project, 'laravel/framework');
    }

    public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
    {
        if ($opts->wantsRoutesOnly()) {
            return [];
        }
        return JobCollector::collect($project->rootPath . '/app/Jobs', $reflector);
    }
}
