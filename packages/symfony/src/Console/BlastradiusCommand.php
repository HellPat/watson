<?php

declare(strict_types=1);

namespace Watson\Symfony\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\RouterInterface;
use Watson\Core\Analysis\Blastradius;
use Watson\Core\Diff\DiffSpec;
use Watson\Core\Output\Envelope;
use Watson\Core\Output\Renderer;
use Watson\Symfony\Runtime\Collector;

#[AsCommand(name: 'watson:blastradius', description: 'Report entry points whose handler files are in the diff.')]
final class BlastradiusCommand extends Command
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('revisions', InputArgument::IS_ARRAY, 'git diff revision spec(s)')
            ->addOption('cached', null, InputOption::VALUE_NONE, 'compare staged index vs HEAD')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (json|md|text)', 'json')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Discovery scope (routes|all)', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoPath = $this->projectDir;
        /** @var list<string> $revisions */
        $revisions = (array) $input->getArgument('revisions');
        $spec = DiffSpec::resolve($repoPath, $revisions, (bool) $input->getOption('cached'));

        $envelope = new Envelope(
            language: 'php',
            framework: 'symfony',
            rootPath: $repoPath,
            base: $spec->baseDisplay,
            head: $spec->headDisplay,
        );

        $eps = Collector::collect($this->router, $this->getApplication(), $repoPath, (string) $input->getOption('scope'));

        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                '<comment>watson: %d entry points · diff %s..%s</comment>',
                count($eps),
                $spec->baseDisplay,
                $spec->headDisplay,
            ));
        }

        Blastradius::run(
            $envelope,
            $repoPath,
            $spec,
            $eps,
        );

        $output->write(Renderer::render((string) $input->getOption('format'), $envelope));

        return self::SUCCESS;
    }
}
