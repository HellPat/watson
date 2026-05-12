<?php

declare(strict_types=1);

namespace Watson\Cli;

use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\Source\SymfonyConsoleSource;
use Watson\Core\Discovery\ConsoleCommandCollector;
use Watson\Core\Discovery\PhpUnitCollector;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Collect Symfony entry points: routes, console commands, message
 * handlers, phpunit tests.
 *
 * Runtime commands come from `bin/console debug:container` via
 * {@see SymfonyConsoleSource}; the AST scan via
 * {@see ConsoleCommandCollector} catches the rest (standalone CLI
 * tools, packages without a `bin/console` front-controller). Both
 * sources are merged + de-duped by handler FQN.
 */
final class SymfonyEntrypointResolver
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
        $source = new SymfonyConsoleSource($project, $reflector, $appEnv);

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
            ...$source->messageHandlers(),
            ...PhpUnitCollector::collect($project->rootPath . '/tests', $reflector),
        ];
    }
}
