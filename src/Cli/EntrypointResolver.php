<?php

declare(strict_types=1);

namespace Watson\Cli;

use Composer\Autoload\ClassLoader;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\Source\LaravelArtisanSource;
use Watson\Cli\Source\SymfonyConsoleSource;
use Watson\Core\Discovery\ConsoleCommandCollector;
use Watson\Core\Discovery\JobCollector;
use Watson\Core\Discovery\PhpUnitCollector;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Glue between framework detection and the per-framework Source / Collector
 * stack. Shared by `list-entrypoints` and `blastradius` so both subcommands
 * see exactly the same entry-point set.
 *
 * @phpstan-type ResolverOpts array{scope?: string, app_env?: string}
 */
final class EntrypointResolver
{
    public const SCOPE_ROUTES = 'routes';
    public const SCOPE_ALL = 'all';

    /**
     * @param ResolverOpts $opts
     * @return list<EntryPoint>
     */
    public static function collect(Project $project, array $opts = []): array
    {
        $scope = $opts['scope'] ?? self::SCOPE_ALL;
        $appEnv = $opts['app_env'] ?? 'dev';

        $classLoader = self::loadConsumerClassLoader($project->rootPath);
        $reflector   = new StaticReflector($project->rootPath, $classLoader);

        return $project->framework === Framework::Symfony
            ? self::collectSymfony($project, $reflector, $scope, $appEnv)
            : self::collectLaravel($project, $reflector, $scope, $appEnv);
    }

    private static function loadConsumerClassLoader(string $projectRoot): ?ClassLoader
    {
        $autoload = $projectRoot . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return null;
        }
        $loader = require $autoload;
        return $loader instanceof ClassLoader ? $loader : null;
    }

    /** @return list<EntryPoint> */
    private static function collectSymfony(
        Project $project,
        StaticReflector $reflector,
        string $scope,
        string $appEnv,
    ): array {
        $source = new SymfonyConsoleSource($project, $reflector, $appEnv);

        $entryPoints = $source->routes();
        if ($scope === self::SCOPE_ALL) {
            $runtimeCommands = $source->commands();
            // Merge runtime + AST-discovered commands so projects without
            // a `bin/console` front-controller (standalone CLI tools)
            // still surface their commands. De-dup by handler FQN.
            $astCommands = ConsoleCommandCollector::collect(
                self::commandScanDirs($project->rootPath),
                $reflector,
            );
            $entryPoints = [
                ...$entryPoints,
                ...self::dedupByHandlerFqn([...$runtimeCommands, ...$astCommands]),
                ...$source->messageHandlers(),
                ...PhpUnitCollector::collect($project->rootPath . '/tests', $reflector),
            ];
        }

        return $entryPoints;
    }

    /**
     * Directories to AST-scan for `Symfony\Component\Console\Command\Command`
     * subclasses. Covers the Symfony default (`src/`), Laravel's
     * `app/Console/Commands`, and any extra location callers add via
     * `--project=…`.
     *
     * @return list<string>
     */
    private static function commandScanDirs(string $rootPath): array
    {
        $candidates = [
            $rootPath . '/src',
            $rootPath . '/app/Console/Commands',
        ];
        return array_values(array_filter($candidates, 'is_dir'));
    }

    /**
     * @param list<EntryPoint> $entryPoints
     * @return list<EntryPoint>
     */
    private static function dedupByHandlerFqn(array $entryPoints): array
    {
        $seen = [];
        $out = [];
        foreach ($entryPoints as $entryPoint) {
            if (isset($seen[$entryPoint->handlerFqn])) {
                continue;
            }
            $seen[$entryPoint->handlerFqn] = true;
            $out[] = $entryPoint;
        }
        return $out;
    }

    /** @return list<EntryPoint> */
    private static function collectLaravel(
        Project $project,
        StaticReflector $reflector,
        string $scope,
        string $appEnv,
    ): array {
        $source = new LaravelArtisanSource($project, $reflector, $appEnv);

        $entryPoints = $source->routes();
        if ($scope === self::SCOPE_ALL) {
            $runtimeCommands = $source->commands();
            $astCommands = ConsoleCommandCollector::collect(
                self::commandScanDirs($project->rootPath),
                $reflector,
            );
            $entryPoints = [
                ...$entryPoints,
                ...self::dedupByHandlerFqn([...$runtimeCommands, ...$astCommands]),
                ...JobCollector::collect($project->rootPath . '/app/Jobs', $reflector),
                ...PhpUnitCollector::collect($project->rootPath . '/tests', $reflector),
            ];
        }

        return $entryPoints;
    }
}
