<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Symfony\Component\Process\Process;
use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Outside-in Laravel source. Shells out to `php artisan route:list --json`
 * and `php artisan list --format=json`. Better Reflection is used to
 * resolve handlers to file + line — the project's autoloader is never
 * invoked.
 */
final class LaravelArtisanSource
{
    public function __construct(
        private readonly Project $project,
        private readonly StaticReflector $reflector,
        private readonly string $appEnv = 'dev',
    ) {
    }

    /** @return list<EntryPoint> */
    public function routes(): array
    {
        $proc = new Process(
            ['php', '-d', 'display_errors=stderr', $this->project->consoleScript, 'route:list', '--json', '--env=' . $this->appEnv],
            $this->project->rootPath,
        );
        $proc->mustRun();
        $data = json_decode($proc->getOutput(), true);
        if (!is_array($data)) {
            throw new \RuntimeException('laravel route:list did not return JSON');
        }

        $out = [];
        foreach ($data as $route) {
            if (!is_array($route)) {
                continue;
            }
            $action = (string) ($route['action'] ?? '');
            [$handlerFqn, $handlerPath, $handlerLine] = $this->resolveAction($action);
            $methods = array_values(array_diff(
                $this->splitMethods((string) ($route['method'] ?? '')),
                ['HEAD'],
            ));
            $uri = '/' . ltrim((string) ($route['uri'] ?? ''), '/');
            $name = $route['name'] ?? sprintf('%s %s', implode('|', $methods), $uri);

            $out[] = new EntryPoint(
                kind: 'laravel.route',
                name: (string) $name,
                handlerFqn: $handlerFqn,
                handlerPath: $handlerPath,
                handlerLine: $handlerLine,
                source: Source::Runtime,
                extra: ['path' => $uri, 'methods' => $methods],
            );
        }

        return $out;
    }

    /**
     * Boot the target Laravel kernel via an inline `php -r` runner and dump
     * `Artisan::all()` as JSON. Gives us name → handler-class authoritatively
     * from Laravel's own runtime registry, regardless of where command
     * classes live in the source tree. Vendor commands are filtered by file
     * path so users only see their own surface.
     *
     * @return list<EntryPoint>
     */
    public function commands(): array
    {
        $runner = <<<'PHP'
            $root = $argv[1] ?? getcwd();
            require $root . '/vendor/autoload.php';
            $app = require $root . '/bootstrap/app.php';
            $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();
            $out = [];
            foreach ($kernel->all() as $name => $cmd) {
                $ref = new \ReflectionClass($cmd);
                $out[] = [
                    'name' => $name,
                    'class' => $ref->getName(),
                    'file' => $ref->getFileName() ?: '',
                ];
            }
            echo json_encode($out);
            PHP;
        $proc = new Process(
            ['php', '-d', 'display_errors=stderr', '-d', 'error_reporting=0', '-r', $runner, '--', $this->project->rootPath],
            $this->project->rootPath,
            ['APP_ENV' => $this->appEnv],
        );
        $proc->mustRun();
        $data = json_decode($proc->getOutput(), true);
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $entry['name'] ?? null;
            $class = $entry['class'] ?? null;
            $file = (string) ($entry['file'] ?? '');
            if (!is_string($name) || !is_string($class) || $name === '' || $class === '') {
                continue;
            }
            if ($file === '' || str_contains($file, '/vendor/')) {
                continue;
            }

            $line = 0;
            $classRef = $this->reflector->reflectClass($class);
            if ($classRef !== null) {
                $line = $classRef->getStartLine() ?: 0;
                try {
                    $handle = $classRef->getMethod('handle');
                    if ($handle !== null) {
                        $line = $handle->getStartLine() ?: $line;
                    }
                } catch (\Throwable) {
                    // class-level line
                }
            }

            $out[] = new EntryPoint(
                kind: 'laravel.command',
                name: $name,
                handlerFqn: $class . '::handle',
                handlerPath: $file,
                handlerLine: $line,
                source: Source::Runtime,
            );
        }

        return $out;
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function resolveAction(string $action): array
    {
        if ($action === '' || $action === 'Closure') {
            return ['<closure>', '', 0];
        }
        $at = strpos($action, '@');
        $class = $at === false ? $action : substr($action, 0, $at);
        $method = $at === false ? '__invoke' : substr($action, $at + 1);

        return $this->reflector->locateMethod($class, $method);
    }

    /** @return list<string> */
    private function splitMethods(string $methodSpec): array
    {
        if ($methodSpec === '' || $methodSpec === 'ANY') {
            return [];
        }

        return array_values(array_filter(explode('|', $methodSpec), static fn (string $m): bool => $m !== ''));
    }

}
