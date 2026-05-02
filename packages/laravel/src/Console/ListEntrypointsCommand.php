<?php

declare(strict_types=1);

namespace Watson\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Routing\Router;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;
use Watson\Core\Output\Envelope;

/**
 * Snapshot every entry point Laravel actually wired up at runtime. We
 * prefer the runtime registry over static source-scan because Laravel's
 * service providers can register routes, commands, listeners and queued
 * jobs at boot time — invisible to a pure-AST scan.
 */
final class ListEntrypointsCommand extends Command
{
    protected $signature = 'watson:list-entrypoints
        {--format=json : Output format (json|md|text)}';

    protected $description = 'List every entry point Laravel registered (routes, commands, jobs).';

    public function handle(Router $router, ConsoleKernel $consoleKernel): int
    {
        $envelope = new Envelope(
            language: 'php',
            framework: 'laravel',
            rootPath: base_path(),
        );

        $entryPoints = [
            ...$this->collectRoutes($router),
            ...$this->collectCommands($consoleKernel),
        ];

        $envelope->pushAnalysis('list-entrypoints', '0.2.0-dev', [
            'entry_points' => $entryPoints,
        ]);

        $this->output->writeln(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @return list<EntryPoint> */
    private function collectRoutes(Router $router): array
    {
        $out = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $action = $route->getAction();
            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            // Action shapes:
            //   ['controller' => 'App\Http\Controllers\Foo@bar']         (string)
            //   ['controller' => 'App\Http\Controllers\Foo']             (invokable)
            //   ['uses' => 'App\Http\Controllers\Foo@bar']               (older form)
            //   ['uses' => Closure]                                       (closure route)
            $controller = $action['controller'] ?? $action['uses'] ?? null;
            [$handlerFqn, $handlerPath, $handlerLine] = $this->resolveHandler($controller);

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
    private function collectCommands(ConsoleKernel $kernel): array
    {
        $out = [];
        // Force the kernel to register all artisan commands so `Artisan::all()`
        // returns the full set including those declared in service providers.
        if (method_exists($kernel, 'all')) {
            $commands = $kernel->all();
        } else {
            $commands = \Illuminate\Support\Facades\Artisan::all();
        }

        foreach ($commands as $name => $command) {
            $reflection = new \ReflectionClass($command);
            $handlerFqn = $reflection->getName();
            $handlerPath = (string) $reflection->getFileName();
            $handlerLine = $reflection->getStartLine() ?: 0;

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
    private function resolveHandler(mixed $controller): array
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

        // Closure / unknown — best effort.
        return ['<closure>', '', 0];
    }
}
