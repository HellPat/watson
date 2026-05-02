<?php

declare(strict_types=1);

namespace Watson\Symfony\Runtime;

use Symfony\Component\Routing\RouterInterface;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

final class RouteCollector
{
    /** @return list<EntryPoint> */
    public static function collect(RouterInterface $router): array
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
}
