<?php

declare(strict_types=1);

namespace Watson\Cli;

use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\Source\SymfonyConsoleSource;
use Watson\Core\Discovery\PhpUnitCollector;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Collect Symfony entry points: routes, console commands, message
 * handlers, phpunit tests.
 *
 * Commands come from `bin/console debug:container --tag=console.command`
 * via {@see SymfonyConsoleSource} — the same source the framework uses
 * internally. No AST-based fallback: standalone CLI tools without a
 * `bin/console` front-controller are out of scope.
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

        return [
            ...$entryPoints,
            ...$source->commands(),
            ...$source->messageHandlers(),
            ...PhpUnitCollector::collect($project->rootPath . '/tests', $reflector),
        ];
    }
}
