<?php

declare(strict_types=1);

namespace Watson\Cli;

use Watson\Cli\Reflection\StaticReflector;
use Watson\Core\Discovery\ConsoleCommandCollector;
use Watson\Core\Discovery\PhpUnitCollector;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Collect entry points for a standalone Symfony Console application —
 * a CLI tool that uses `symfony/console` directly without the full
 * Symfony framework runtime, so `bin/console debug:container --tag=`
 * is unavailable.
 *
 * Strategy:
 *
 *  - Commands → AST scan for `#[AsCommand]` / `Command` subclasses,
 *    rooted at the project's own `composer.json` PSR-4 autoload paths.
 *    No hard-coded `src/` / `app/Console/Commands` convention.
 *  - Tests    → standard `tests/` walk via {@see PhpUnitCollector}.
 *  - Routes / jobs / message handlers → none; not applicable.
 */
final class ConsoleAppEntrypointResolver
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
        if ($scope !== EntrypointResolver::SCOPE_ALL) {
            return [];
        }

        $scanDirs = self::psr4Roots($project->rootPath);

        return [
            ...ConsoleCommandCollector::collect($scanDirs, $reflector),
            ...PhpUnitCollector::collect($project->rootPath . '/tests', $reflector),
        ];
    }

    /**
     * Read `composer.json` `autoload.psr-4` (and `autoload-dev.psr-4`)
     * and return each declared source directory as an absolute path.
     * Empty / non-existent directories are dropped.
     *
     * Authoritative over any convention-based guess: the project tells
     * us where its code lives.
     *
     * @return list<string>
     */
    private static function psr4Roots(string $rootPath): array
    {
        $composer = $rootPath . '/composer.json';
        if (!is_file($composer)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($composer), true);
        if (!is_array($decoded)) {
            return [];
        }

        $dirs = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $psr4 = $decoded[$section]['psr-4'] ?? null;
            if (!is_array($psr4)) {
                continue;
            }
            foreach ($psr4 as $paths) {
                foreach ((array) $paths as $rel) {
                    if (!is_string($rel) || $rel === '') {
                        continue;
                    }
                    $abs = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($rel, DIRECTORY_SEPARATOR);
                    if (is_dir($abs)) {
                        $dirs[$abs] = true;
                    }
                }
            }
        }
        return array_keys($dirs);
    }
}
