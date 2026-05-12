<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;

/**
 * Runtime Laravel artisan commands via `php artisan list --format=json`.
 */
final class LaravelCommandsRuntimeSource implements EntrypointSource
{
    public function name(): string
    {
        return 'laravel.commands.runtime';
    }

    public function canHandle(Project $project): bool
    {
        return is_file($project->rootPath . '/artisan');
    }

    public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
    {
        if ($opts->wantsRoutesOnly()) {
            return [];
        }
        return (new LaravelArtisanSource($project, $reflector, $opts->appEnv))->commands();
    }
}
