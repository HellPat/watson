<?php

declare(strict_types=1);

namespace Watson\Cli;

use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\Source\LaravelArtisanSource;
use Watson\Core\Discovery\ConsoleCommandCollector;
use Watson\Core\Discovery\JobCollector;
use Watson\Core\Discovery\PhpUnitCollector;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Collect Laravel entry points: routes, artisan commands, queued
 * jobs, phpunit tests.
 *
 * Runtime commands come from `artisan list` via
 * {@see LaravelArtisanSource}; the AST scan via
 * {@see ConsoleCommandCollector} catches the rest. Both sources are
 * merged + de-duped by handler FQN.
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

        $runtimeCommands = $source->commands();
        $astCommands     = ConsoleCommandCollector::collect(
            EntrypointMerging::commandScanDirs($project->rootPath),
            $reflector,
        );

        return [
            ...$entryPoints,
            ...EntrypointMerging::dedupByHandlerFqn([...$runtimeCommands, ...$astCommands]),
            ...JobCollector::collect($project->rootPath . '/app/Jobs', $reflector),
            ...PhpUnitCollector::collect($project->rootPath . '/tests', $reflector),
        ];
    }
}
