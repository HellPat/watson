<?php

declare(strict_types=1);

namespace Watson\Cli;

use Composer\Autoload\ClassLoader;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Façade between framework detection and the per-framework resolver.
 * Shared by `list-entrypoints` and `blastradius` so both subcommands
 * see exactly the same entry-point set.
 *
 * The real per-framework work lives in
 * {@see SymfonyEntrypointResolver} / {@see LaravelEntrypointResolver}.
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
            ? SymfonyEntrypointResolver::collect($project, $reflector, $scope, $appEnv)
            : LaravelEntrypointResolver::collect($project, $reflector, $scope, $appEnv);
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
}
