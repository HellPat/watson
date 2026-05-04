<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Roave\BetterReflection\Reflection\ReflectionClass;
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

    /** @return list<EntryPoint> */
    public function commands(): array
    {
        $proc = new Process(
            ['php', '-d', 'display_errors=stderr', $this->project->consoleScript, 'list', '--format=json', '--env=' . $this->appEnv],
            $this->project->rootPath,
        );
        $proc->mustRun();
        $data = json_decode($proc->getOutput(), true);
        if (!is_array($data) || !isset($data['commands']) || !is_array($data['commands'])) {
            return [];
        }

        $runtimeNames = [];
        foreach ($data['commands'] as $cmd) {
            if (is_array($cmd) && isset($cmd['name']) && is_string($cmd['name'])) {
                $runtimeNames[$cmd['name']] = true;
            }
        }

        $out = [];
        foreach ($this->reflector->reflectAllInDirs([$this->project->rootPath . '/app/Console/Commands']) as $class) {
            try {
                if (!$class->isSubclassOf('Illuminate\\Console\\Command')) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $name = $this->extractLaravelCommandName($class);
            if ($name === null || !isset($runtimeNames[$name])) {
                continue;
            }
            $line = $class->getStartLine() ?: 0;
            try {
                $handle = $class->getMethod('handle');
                if ($handle !== null) {
                    $line = $handle->getStartLine() ?: $line;
                }
            } catch (\Throwable) {
                // class-level line
            }
            $out[] = new EntryPoint(
                kind: 'laravel.command',
                name: $name,
                handlerFqn: $class->getName() . '::handle',
                handlerPath: (string) $class->getFileName(),
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

    private function extractLaravelCommandName(ReflectionClass $class): ?string
    {
        try {
            $sig = $class->getProperty('signature');
            if ($sig !== null) {
                $value = $sig->getDefaultValue();
                if (is_string($value)) {
                    $first = strtok($value, " \n\t");
                    if ($first !== false && $first !== '') {
                        return $first;
                    }
                }
            }
        } catch (\Throwable) {
            // try $name fallback
        }
        try {
            $nameProp = $class->getProperty('name');
            if ($nameProp !== null) {
                $value = $nameProp->getDefaultValue();
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        } catch (\Throwable) {
            // try attribute fallback
        }
        foreach ($class->getAttributesByName('Symfony\\Component\\Console\\Attribute\\AsCommand') as $attr) {
            $args = $attr->getArguments();
            $candidate = $args[0] ?? $args['name'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
