<?php

declare(strict_types=1);

namespace Watson\Cli;

use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\Source\LaravelArtisanSource;
use Watson\Core\Discovery\JobCollector;
use Watson\Core\Discovery\PhpUnitCollector;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Collect Laravel entry points: routes, artisan commands, queued
 * jobs, phpunit tests.
 *
 * Commands come from `artisan list --format=json` via
 * {@see LaravelArtisanSource}. Jobs are AST-scanned from `app/Jobs/`
 * because the Laravel runtime has no equivalent of `debug:container
 * --tag=`.
 */
final class LaravelEntrypointResolver
{
    /**
     * @return list<EntryPoint>
     */
    public static function collect(
        Project $project,
        StaticReflector $reflector,
        string $scope,
        string $appEnv,
    ): array {
        $source = new LaravelArtisanSource($project, $reflector, $appEnv);

        $entryPoints = $source->routes();
        if ($scope !== EntrypointResolver::SCOPE_ALL) {
            return $entryPoints;
        }

        return [
            ...$entryPoints,
            ...$source->commands(),
            ...JobCollector::collect($project->rootPath . '/app/Jobs', $reflector),
            ...PhpUnitCollector::collect($project->rootPath . '/tests', $reflector),
        ];
    }
}
