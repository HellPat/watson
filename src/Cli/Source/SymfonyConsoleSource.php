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
            ['php', '-d', 'display_errors=stderr', $this->project->consoleScript, 'debug:router', '--format=json', '--env=' . $this->appEnv],
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
     * Cross-checks runtime command names from `bin/console list --format=json`
     * against `#[AsCommand]` attributes in user code. We deliberately skip
     * vendor/ — users only care about their own command surface.
     *
     * @return list<EntryPoint>
     */
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
        foreach ($this->reflector->reflectAllInDirs([$this->project->rootPath . '/src']) as $class) {
            $name = $this->extractCommandName($class);
            if ($name === null || !isset($runtimeNames[$name])) {
                continue;
            }
            $file = (string) $class->getFileName();
            $line = $class->getStartLine() ?: 0;
            try {
                $execute = $class->getMethod('execute');
                if ($execute !== null) {
                    $line = $execute->getStartLine() ?: $line;
                }
            } catch (\Throwable) {
                // class-level line
            }

            $out[] = new EntryPoint(
                kind: 'symfony.command',
                name: $name,
                handlerFqn: $class->getName() . '::execute',
                handlerPath: $file,
                handlerLine: $line,
                source: Source::Runtime,
            );
        }

        return $out;
    }

    private function extractCommandName(ReflectionClass $class): ?string
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
