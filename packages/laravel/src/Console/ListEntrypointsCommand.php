<?php

declare(strict_types=1);

namespace Watson\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Routing\Router;
use Watson\Core\Output\Envelope;
use Watson\Core\Output\Renderer;
use Watson\Laravel\Runtime\RouteCollector;

final class ListEntrypointsCommand extends Command
{
    protected $signature = 'watson:list-entrypoints
        {--format=json : Output format (json|md|text)}';

    protected $description = 'List every entry point Laravel registered (routes, commands).';

    public function handle(Router $router, ConsoleKernel $consoleKernel): int
    {
        $envelope = new Envelope(
            language: 'php',
            framework: 'laravel',
            rootPath: base_path(),
        );

        $envelope->pushAnalysis('list-entrypoints', '0.2.0-dev', [
            'entry_points' => RouteCollector::collect($router, $consoleKernel),
        ]);

        $this->output->write(Renderer::render($this->option('format'), $envelope));

        return self::SUCCESS;
    }
}
