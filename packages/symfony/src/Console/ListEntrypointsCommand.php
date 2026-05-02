<?php

declare(strict_types=1);

namespace Watson\Symfony\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\RouterInterface;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;
use Watson\Core\Output\Envelope;

/**
 * Snapshot every entry point Symfony's container actually wired up. The
 * router's `RouteCollection` is the runtime-authoritative truth — it
 * contains attribute, YAML, XML, PHP-config and service-tag-registered
 * routes, all merged. No static analysis approximation.
 */
#[AsCommand(name: 'watson:list-entrypoints', description: 'List every entry point Symfony registered (routes, commands).')]
final class ListEntrypointsCommand extends Command
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (json|md|text)', 'json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = new Envelope(
            language: 'php',
            framework: 'symfony',
            rootPath: $this->projectDir,
        );

        $entryPoints = $this->collectRoutes();

        $envelope->pushAnalysis('list-entrypoints', '0.2.0-dev', [
            'entry_points' => $entryPoints,
        ]);

        $output->writeln(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /** @return list<EntryPoint> */
    private function collectRoutes(): array
    {
        $out = [];
        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            [$handlerFqn, $handlerPath, $handlerLine] = $this->resolveController($controller);

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
    private function resolveController(mixed $controller): array
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
