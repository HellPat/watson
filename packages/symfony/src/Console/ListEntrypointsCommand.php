<?php

declare(strict_types=1);

namespace Watson\Symfony\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\RouterInterface;
use Watson\Core\Output\Envelope;
use Watson\Core\Output\Renderer;
use Watson\Symfony\Runtime\RouteCollector;

#[AsCommand(name: 'watson:list-entrypoints', description: 'List every entry point Symfony registered.')]
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

        $envelope->pushAnalysis('list-entrypoints', '0.2.0-dev', [
            'entry_points' => RouteCollector::collect($this->router),
        ]);

        $output->write(Renderer::render((string) $input->getOption('format'), $envelope));

        return self::SUCCESS;
    }
}
