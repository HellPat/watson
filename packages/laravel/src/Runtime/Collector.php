<?php

declare(strict_types=1);

namespace Watson\Laravel\Runtime;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Routing\Router;
use Watson\Core\Discovery\PhpUnitCollector;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Aggregate every Laravel entry-point category into one list: runtime
 * routes + commands, plus filesystem-discovered jobs / listeners /
 * PHPUnit tests. The verbosity tier flag lets callers narrow output —
 * `--scope=routes` is the cheap default; `--scope=all` walks the
 * filesystem for jobs/listeners/tests.
 */
final class Collector
{
    public const SCOPE_ROUTES = 'routes';
    public const SCOPE_ALL = 'all';

    /** @return list<EntryPoint> */
    public static function collect(
        Router $router,
        ConsoleKernel $consoleKernel,
        string $basePath,
        string $scope = self::SCOPE_ALL,
    ): array {
        if ($scope === self::SCOPE_ROUTES) {
            return RouteCollector::collect($router, null);
        }

        $appPath = $basePath . '/app';
        $testsPath = $basePath . '/tests';

        return [
            ...RouteCollector::collect($router, $consoleKernel),
            ...JobCollector::collect($appPath),
            ...ListenerCollector::collect($appPath),
            ...PhpUnitCollector::collect($testsPath),
        ];
    }
}
