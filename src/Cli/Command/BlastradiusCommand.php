<?php

declare(strict_types=1);

namespace Watson\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Watson\Cli\EntrypointResolver;
use Watson\Cli\ProjectDetector;
use Watson\Core\Analysis\Blastradius;
use Watson\Core\Diff\DiffSpec;
use Watson\Core\Output\Envelope;
use Watson\Core\Output\Renderer;

#[AsCommand(name: 'blastradius', description: 'Report entry points whose handler files are in the diff.')]
final class BlastradiusCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('revisions', InputArgument::IS_ARRAY, 'git diff revision spec(s)')
            ->addOption('cached', null, InputOption::VALUE_NONE, 'compare staged index vs HEAD')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project root (default: walk up from CWD)')
            ->addOption('framework', null, InputOption::VALUE_REQUIRED, 'symfony|laravel (auto-detect when omitted)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (json|md|text)', 'json')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Discovery scope (routes|all)', 'all')
            ->addOption('app-env', null, InputOption::VALUE_REQUIRED, 'env passed to bin/console / artisan', 'dev');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startDir = (string) ($input->getOption('project') ?? (getcwd() ?: '.'));
        $force = $input->getOption('framework');
        $project = ProjectDetector::detect($startDir, is_string($force) && $force !== '' ? $force : null);

        /** @var list<string> $revisions */
        $revisions = (array) $input->getArgument('revisions');
        $spec = DiffSpec::resolve($project->rootPath, $revisions, (bool) $input->getOption('cached'));

        $envelope = new Envelope(
            language: 'php',
            framework: $project->framework->value,
            rootPath: $project->rootPath,
            base: $spec->baseDisplay,
            head: $spec->headDisplay,
        );

        $eps = EntrypointResolver::collect($project, [
            'scope' => (string) $input->getOption('scope'),
            'app_env' => (string) $input->getOption('app-env'),
        ]);

        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                '<comment>watson: %d entry points · diff %s..%s</comment>',
                count($eps),
                $spec->baseDisplay,
                $spec->headDisplay,
            ));
        }

        Blastradius::run($envelope, $project->rootPath, $spec, $eps);

        $output->write(Renderer::render((string) $input->getOption('format'), $envelope));

        return self::SUCCESS;
    }
}
