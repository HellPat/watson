<?php

declare(strict_types=1);

namespace Watson\Symfony\Runtime;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Routing\RouterInterface;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Pulls Symfony's runtime registries — `RouterInterface::getRouteCollection()`
 * and `Application::all()` — into the framework-neutral `EntryPoint` shape.
 * Runtime is the source of truth: YAML / XML / PHP-config routes,
 * service-tag-registered commands and any package-shipped extras all flow
 * through these registries, so a static AST walker can never match this
 * surface.
 */
final class RouteCollector
{
    /** @return list<EntryPoint> */
    public static function collect(RouterInterface $router, ?Application $application = null): array
    {
        return [
            ...self::routes($router),
            ...($application !== null ? self::commands($application) : []),
        ];
    }

    /** @return list<EntryPoint> */
    private static function routes(RouterInterface $router): array
    {
        $out = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            [$handlerFqn, $handlerPath, $handlerLine] = self::resolveController($controller);

            $out[] = new EntryPoint(
                kind: 'symfony.route',
                name: (string) $name,
                handlerFqn: $handlerFqn,
                handlerPath: $handlerPath,
                handlerLine: $handlerLine,
                source: Source::Runtime,
                extra: [
                    'path' => $route->getPath(),
                    'methods' => $route->getMethods(),
                ],
            );
        }

        return $out;
    }

    /** @return list<EntryPoint> */
    private static function commands(Application $application): array
    {
        $out = [];
        foreach ($application->all() as $name => $command) {
            // Symfony wraps service-tag commands in LazyCommand; unwrap so
            // reflection lands on the real handler class, not the proxy.
            $real = $command instanceof LazyCommand ? $command->getCommand() : $command;
            $reflection = new \ReflectionClass($real);
            $handlerPath = (string) $reflection->getFileName();

            // Skip framework-internal / vendor commands; user only cares
            // about their own app's command surface.
            if (self::isVendorCommand($handlerPath)) {
                continue;
            }

            $handlerLine = $reflection->hasMethod('execute')
                ? ($reflection->getMethod('execute')->getStartLine() ?: 0)
                : ($reflection->getStartLine() ?: 0);

            $out[] = new EntryPoint(
                kind: 'symfony.command',
                name: (string) $name,
                handlerFqn: $reflection->getName() . '::execute',
                handlerPath: $handlerPath,
                handlerLine: $handlerLine,
                source: Source::Runtime,
            );
        }

        return $out;
    }

    /** @return array{0: string, 1: string, 2: int} */
    private static function resolveController(mixed $controller): array
    {
        if (!is_string($controller)) {
            return ['<closure>', '', 0];
        }
        $class = str_contains($controller, '::')
            ? substr($controller, 0, (int) strpos($controller, '::'))
            : $controller;
        $method = str_contains($controller, '::')
            ? substr($controller, (int) strpos($controller, '::') + 2)
            : '__invoke';

        try {
            $reflection = new \ReflectionClass($class);
            $path = (string) $reflection->getFileName();
            $line = $reflection->hasMethod($method)
                ? ($reflection->getMethod($method)->getStartLine() ?: 0)
                : ($reflection->getStartLine() ?: 0);

            return [$class . '::' . $method, $path, $line];
        } catch (\ReflectionException) {
            return [$class . '::' . $method, '', 0];
        }
    }

    private static function isVendorCommand(string $path): bool
    {
        return $path === '' || str_contains($path, '/vendor/');
    }
}
