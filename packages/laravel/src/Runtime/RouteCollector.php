<?php

declare(strict_types=1);

namespace Watson\Laravel\Runtime;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Routing\Router;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Pulls Laravel's runtime registries — `Route::getRoutes()`,
 * `Artisan::all()` — into our framework-neutral `EntryPoint` shape.
 * Runtime is the source of truth here: service providers, package
 * auto-discovery and `Route::resource()` expansion all go through the
 * router, and any of those can be invisible to a static AST scan.
 */
final class RouteCollector
{
    /** @return list<EntryPoint> */
    public static function collect(Router $router, ConsoleKernel $kernel): array
    {
        return [
            ...self::routes($router),
            ...self::commands($kernel),
        ];
    }

    /** @return list<EntryPoint> */
    private static function routes(Router $router): array
    {
        $out = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $action = $route->getAction();
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $controller = $action['controller'] ?? $action['uses'] ?? null;
            [$handlerFqn, $handlerPath, $handlerLine] = self::resolveHandler($controller);

            $out[] = new EntryPoint(
                kind: 'laravel.route',
                name: $route->getName() ?? sprintf('%s %s', implode('|', $methods), $route->uri()),
                handlerFqn: $handlerFqn,
                handlerPath: $handlerPath,
                handlerLine: $handlerLine,
                source: Source::Runtime,
                extra: [
                    'path' => '/' . ltrim($route->uri(), '/'),
                    'methods' => $methods,
                ],
            );
        }

        return $out;
    }

    /** @return list<EntryPoint> */
    private static function commands(ConsoleKernel $kernel): array
    {
        $out = [];
        $commands = method_exists($kernel, 'all')
            ? $kernel->all()
            : \Illuminate\Support\Facades\Artisan::all();

        foreach ($commands as $name => $command) {
            $reflection = new \ReflectionClass($command);
            $handlerFqn = $reflection->getName();
            $handlerPath = (string) $reflection->getFileName();
            $handlerLine = $reflection->getStartLine() ?: 0;

            // Skip framework-internal commands; user only cares about app code.
            if (self::isVendorCommand($handlerPath)) {
                continue;
            }

            $out[] = new EntryPoint(
                kind: 'laravel.command',
                name: (string) $name,
                handlerFqn: $handlerFqn . '::handle',
                handlerPath: $handlerPath,
                handlerLine: $handlerLine,
                source: Source::Runtime,
            );
        }

        return $out;
    }

    /** @return array{0: string, 1: string, 2: int} */
    private static function resolveHandler(mixed $controller): array
    {
        if (is_string($controller)) {
            $class = str_contains($controller, '@')
                ? substr($controller, 0, (int) strpos($controller, '@'))
                : $controller;
            $method = str_contains($controller, '@')
                ? substr($controller, (int) strpos($controller, '@') + 1)
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

        return ['<closure>', '', 0];
    }

    private static function isVendorCommand(string $path): bool
    {
        return $path === '' || str_contains($path, '/vendor/');
    }
}
