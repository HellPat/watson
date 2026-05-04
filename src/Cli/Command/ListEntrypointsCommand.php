<?php

declare(strict_types=1);

namespace Watson\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Watson\Cli\EntrypointResolver;
use Watson\Cli\ProjectDetector;
use Watson\Core\Output\Envelope;
use Watson\Core\Output\Renderer;

#[AsCommand(name: 'list-entrypoints', description: 'List every entry point the framework registered.')]
final class ListEntrypointsCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project root (default: walk up from CWD)');
        $this->addOption('framework', null, InputOption::VALUE_REQUIRED, 'symfony|laravel (auto-detect when omitted)');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (json|md|text)', 'json');
        $this->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Discovery scope (routes|all)', 'all');
        $this->addOption('app-env', null, InputOption::VALUE_REQUIRED, 'env passed to bin/console / artisan', 'dev');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startDir = (string) ($input->getOption('project') ?? (getcwd() ?: '.'));
        $force = $input->getOption('framework');
        $project = ProjectDetector::detect($startDir, is_string($force) && $force !== '' ? $force : null);

        $eps = EntrypointResolver::collect($project, [
            'scope' => (string) $input->getOption('scope'),
            'app_env' => (string) $input->getOption('app-env'),
        ]);

        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                '<comment>watson: collected %d entry points from %s</comment>',
                count($eps),
                $project->rootPath,
            ));
        }

        $envelope = new Envelope(
            language: 'php',
            framework: $project->framework->value,
            rootPath: $project->rootPath,
        );
        $envelope->pushAnalysis('list-entrypoints', Envelope::TOOL_VERSION, [
            'entry_points' => $eps,
        ]);

        $output->write(Renderer::render((string) $input->getOption('format'), $envelope));

        return self::SUCCESS;
    }
}
