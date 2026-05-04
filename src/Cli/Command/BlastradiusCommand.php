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

#[AsCommand(
    name: 'blastradius',
    description: 'Report which routes, commands, jobs, and listeners are reached by a git diff.',
)]
final class BlastradiusCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('revisions', InputArgument::IS_ARRAY, 'Git diff revisions: <rev>, <a> <b>, <a>..<b>, or <a>...<b> (merge-base). Empty = working tree vs HEAD.')
            ->addOption('cached', null, InputOption::VALUE_NONE, 'Diff staged index vs HEAD instead of working tree')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project root (defaults to walking up from CWD)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text (human terminal), md (markdown for PRs/LLMs), json (machine), tok (tab-separated, token-optimized for LLM pipes)', 'text')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'routes (cheapest, runtime registry only) or all (adds commands / jobs / listeners / tests)', 'all')
            ->addOption('app-env', null, InputOption::VALUE_REQUIRED, 'APP_ENV passed to bin/console / artisan', 'dev');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startDir = (string) ($input->getOption('project') ?? (getcwd() ?: '.'));
        $project = ProjectDetector::detect($startDir);

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
