<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;

/**
 * Runtime Laravel routes via `php artisan route:list --json`.
 */
final class LaravelRoutesSource implements EntrypointSource
{
    public function name(): string
    {
        return 'laravel.routes';
    }

    public function canHandle(Project $project): bool
    {
        return is_file($project->rootPath . '/artisan');
    }

    public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
    {
        return (new LaravelArtisanSource($project, $reflector, $opts->appEnv))->routes();
    }
}
