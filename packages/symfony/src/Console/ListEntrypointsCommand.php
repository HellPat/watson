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
use Watson\Symfony\Runtime\Collector;

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
        $this->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Discovery scope (routes|all)', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = new Envelope(
            language: 'php',
            framework: 'symfony',
            rootPath: $this->projectDir,
        );

        $eps = Collector::collect($this->router, $this->getApplication(), $this->projectDir, (string) $input->getOption('scope'));

        if ($output->isVerbose()) {
            $output->writeln(sprintf('<comment>watson: collected %d entry points</comment>', count($eps)));
        }

        $envelope->pushAnalysis('list-entrypoints', '0.2.0-dev', [
            'entry_points' => $eps,
        ]);

        $output->write(Renderer::render((string) $input->getOption('format'), $envelope));

        return self::SUCCESS;
    }
}
