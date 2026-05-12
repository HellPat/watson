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
 * Outside-in Symfony source. Shells out to `bin/console debug:router` and
 * `bin/console list` for the runtime registries (these are part of
 * symfony/framework-bundle, no watson code in the target kernel). Then
 * uses Better Reflection — never the project's autoloader — to resolve
 * each handler to a file path + line.
 */
final class SymfonyConsoleSource
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
            ['php', '-d', 'display_errors=stderr', $this->project->rootPath . "/bin/console", 'debug:router', '--format=json', '--env=' . $this->appEnv],
            $this->project->rootPath,
        );
        $proc->mustRun();

        $data = json_decode($proc->getOutput(), true);
        if (!is_array($data)) {
            throw new \RuntimeException('symfony debug:router did not return JSON');
        }

        $out = [];
        foreach ($data as $name => $route) {
            if (!is_array($route)) {
                continue;
            }
            $controller = $route['defaults']['_controller'] ?? null;
            [$handlerFqn, $handlerPath, $handlerLine] = $this->resolveController(
                is_string($controller) ? $controller : null,
            );
            $out[] = new EntryPoint(
                kind: 'symfony.route',
                name: (string) $name,
                handlerFqn: $handlerFqn,
                handlerPath: $handlerPath,
                handlerLine: $handlerLine,
                source: Source::Runtime,
                extra: [
                    'path' => (string) ($route['path'] ?? ''),
                    'methods' => $this->splitMethods((string) ($route['method'] ?? '')),
                ],
            );
        }

        return $out;
    }

    /**
     * Pull every service tagged `console.command` straight from the runtime
     * container. Handler class is in the definition; user-facing name is in
     * the tag parameters. Vendor commands are filtered by file path so users
     * only see their own surface — no folder-convention guessing.
     *
     * @return list<EntryPoint>
     */
    public function commands(): array
    {
        $proc = new Process(
            ['php', '-d', 'display_errors=stderr', $this->project->rootPath . "/bin/console", 'debug:container', '--tag=console.command', '--format=json', '--env=' . $this->appEnv],
            $this->project->rootPath,
        );
        $proc->mustRun();
        $data = json_decode($proc->getOutput(), true);
        if (!is_array($data) || !isset($data['definitions']) || !is_array($data['definitions'])) {
            return [];
        }

        $out = [];
        $seenClasses = [];
        foreach ($data['definitions'] as $serviceId => $def) {
            if (!is_array($def)) {
                continue;
            }
            $class = is_string($def['class'] ?? null) ? $def['class'] : (string) $serviceId;
            if ($class === '' || isset($seenClasses[$class])) {
                continue;
            }
            $seenClasses[$class] = true;

            $classRef = $this->reflector->reflectClass($class);
            if ($classRef === null) {
                continue;
            }
            $file = (string) $classRef->getFileName();
            if ($file === '' || str_contains($file, '/vendor/')) {
                continue;
            }

            $name = $this->extractCommandNameFromTags($def['tags'] ?? [])
                ?? $this->extractCommandNameFromAttribute($classRef);
            if ($name === null) {
                continue;
            }

            $line = $classRef->getStartLine() ?: 0;
            try {
                $execute = $classRef->getMethod('execute');
                if ($execute !== null) {
                    $line = $execute->getStartLine() ?: $line;
                }
            } catch (\Throwable) {
                // class-level line
            }

            $out[] = new EntryPoint(
                kind: 'symfony.command',
                name: $name,
                handlerFqn: $class . '::execute',
                handlerPath: $file,
                handlerLine: $line,
                source: Source::Runtime,
            );
        }

        return $out;
    }

    /** @param mixed $tags */
    private function extractCommandNameFromTags(mixed $tags): ?string
    {
        if (!is_array($tags)) {
            return null;
        }
        foreach ($tags as $tag) {
            if (!is_array($tag) || ($tag['name'] ?? null) !== 'console.command') {
                continue;
            }
            $params = $tag['parameters'] ?? null;
            if (is_array($params) && isset($params['command']) && is_string($params['command']) && $params['command'] !== '') {
                return $params['command'];
            }
        }

        return null;
    }

    private function extractCommandNameFromAttribute(ReflectionClass $class): ?string
    {
        foreach ($class->getAttributesByName('Symfony\\Component\\Console\\Attribute\\AsCommand') as $attr) {
            $args = $attr->getArguments();
            $candidate = $args[0] ?? $args['name'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Pull every messenger handler tagged at runtime. The tag's `handles`
     * parameter is often null (Symfony resolves the message type lazily
     * from the handler method's first parameter); fall back to Better
     * Reflection on the method signature in that case.
     *
     * One entry is emitted per (handler-class, method, message) triple —
     * a single class can declare multiple `#[AsMessageHandler]` attributes
     * on different methods.
     *
     * Returns an empty list silently when messenger isn't configured at all
     * (the tag doesn't exist; `debug:container --tag=…` exits non-zero) —
     * watson must not crash on apps that don't use messenger.
     *
     * @return list<EntryPoint>
     */
    public function messageHandlers(): array
    {
        $proc = new Process(
            ['php', '-d', 'display_errors=stderr', $this->project->rootPath . "/bin/console", 'debug:container', '--tag=messenger.message_handler', '--format=json', '--env=' . $this->appEnv],
            $this->project->rootPath,
        );
        $proc->run();
        if (!$proc->isSuccessful()) {
            // No messenger config / no tagged services → treat as zero handlers.
            return [];
        }
        $data = json_decode($proc->getOutput(), true);
        if (!is_array($data) || !isset($data['definitions']) || !is_array($data['definitions'])) {
            return [];
        }

        $out = [];
        foreach ($data['definitions'] as $serviceId => $def) {
            if (!is_array($def)) {
                continue;
            }
            $class = is_string($def['class'] ?? null) ? $def['class'] : (string) $serviceId;
            if ($class === '') {
                continue;
            }

            $classRef = $this->reflector->reflectClass($class);
            if ($classRef === null) {
                continue;
            }
            $file = (string) $classRef->getFileName();
            if ($file === '' || str_contains($file, '/vendor/')) {
                continue;
            }

            foreach ($def['tags'] ?? [] as $tag) {
                if (!is_array($tag) || ($tag['name'] ?? null) !== 'messenger.message_handler') {
                    continue;
                }
                $params = is_array($tag['parameters'] ?? null) ? $tag['parameters'] : [];
                $method = is_string($params['method'] ?? null) && $params['method'] !== '' ? $params['method'] : null;
                $message = is_string($params['handles'] ?? null) && $params['handles'] !== '' ? $params['handles'] : null;
                $bus = is_string($params['bus'] ?? null) && $params['bus'] !== '' ? $params['bus'] : null;

                $resolvedMethod = $method ?? $this->defaultHandlerMethod($classRef);
                if ($resolvedMethod === null) {
                    continue;
                }

                if ($message === null) {
                    $message = $this->inferMessageFromMethod($classRef, $resolvedMethod);
                }
                if ($message === null) {
                    continue;
                }

                $line = $classRef->getStartLine() ?: 0;
                try {
                    $methodRef = $classRef->getMethod($resolvedMethod);
                    if ($methodRef !== null) {
                        $line = $methodRef->getStartLine() ?: $line;
                    }
                } catch (\Throwable) {
                    // class-level fallback
                }

                $extra = ['message' => $message];
                if ($bus !== null) {
                    $extra['bus'] = $bus;
                }

                $out[] = new EntryPoint(
                    kind: 'symfony.message_handler',
                    name: $message,
                    handlerFqn: $class . '::' . $resolvedMethod,
                    handlerPath: $file,
                    handlerLine: $line,
                    source: Source::Runtime,
                    extra: $extra,
                );
            }
        }

        return $out;
    }

    private function defaultHandlerMethod(ReflectionClass $class): ?string
    {
        try {
            if ($class->hasMethod('__invoke')) {
                return '__invoke';
            }
            if ($class->hasMethod('handle')) {
                return 'handle';
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }

    private function inferMessageFromMethod(ReflectionClass $class, string $methodName): ?string
    {
        try {
            $method = $class->getMethod($methodName);
            if ($method === null) {
                return null;
            }
            $params = $method->getParameters();
            if ($params === []) {
                return null;
            }
            $type = $params[0]->getType();
            if ($type === null) {
                return null;
            }
            $name = (string) $type;
            if ($name === '' || str_contains($name, '|') || str_contains($name, '&')) {
                // union/intersection: ambiguous; skip rather than guess.
                return null;
            }

            return ltrim($name, '\\?');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function resolveController(?string $controller): array
    {
        if ($controller === null || $controller === '') {
            return ['<closure>', '', 0];
        }
        $sep = strpos($controller, '::');
        $class = $sep === false ? $controller : substr($controller, 0, $sep);
        $method = $sep === false ? '__invoke' : substr($controller, $sep + 2);

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
