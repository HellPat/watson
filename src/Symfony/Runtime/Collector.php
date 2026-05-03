<?php

declare(strict_types=1);

namespace Watson\Symfony\Runtime;

use Symfony\Component\Console\Application;
use Symfony\Component\Routing\RouterInterface;
use Watson\Core\Discovery\PhpUnitCollector;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Aggregate every Symfony entry-point category. `--scope=routes` keeps it
 * cheap (no filesystem walk); `--scope=all` adds the conventional
 * `tests/` PHPUnit discovery on top of routes + commands.
 */
final class Collector
{
    public const SCOPE_ROUTES = 'routes';
    public const SCOPE_ALL = 'all';

    /** @return list<EntryPoint> */
    public static function collect(
        RouterInterface $router,
        ?Application $application,
        string $projectDir,
        string $scope = self::SCOPE_ALL,
    ): array {
        if ($scope === self::SCOPE_ROUTES) {
            return RouteCollector::collect($router, null);
        }

        return [
            ...RouteCollector::collect($router, $application),
            ...PhpUnitCollector::collect($projectDir . '/tests'),
        ];
    }
}
