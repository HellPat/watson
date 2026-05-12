<?php

declare(strict_types=1);

namespace Watson\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Autoload\ClassLoader;
use Watson\Cli\EntrypointResolver;
use Watson\Cli\ProjectDetector;
use Watson\Core\Analysis\Blastradius;
use Watson\Core\Diff\ChangedFilesReader;
use Watson\Core\Output\Envelope;
use Watson\Core\Output\Renderer;

#[AsCommand(
    name: 'blastradius',
    description: 'Report which routes, commands, jobs, and listeners are reached by the unified diff piped on stdin.',
)]
final class BlastradiusCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Cosmetic label shown as the diff base in the rendered output (e.g. "main").')
            ->addOption('head', null, InputOption::VALUE_REQUIRED, 'Cosmetic label shown as the diff head in the rendered output (e.g. "HEAD").')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project root (defaults to walking up from CWD).')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text (human terminal), md (markdown for PRs / LLMs), json (machine), tok (tab-separated, token-optimised for LLM pipes).', 'text')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'routes (runtime registry only) or all (adds commands / jobs / listeners / tests).', 'all')
            ->addOption('app-env', null, InputOption::VALUE_REQUIRED, 'APP_ENV passed to bin/console / artisan when collecting routes.', 'dev')
            ->addOption('max-depth', null, InputOption::VALUE_REQUIRED, 'Maximum hops the indirect-reach BFS walks from each entry-point handler. Lower = tighter signal, higher = more recall.', '3')
            ->setHelp(<<<HELP
                Reads a unified diff from stdin and reports which framework entry
                points reach the changed methods. Comment-only and whitespace-only
                edits are dropped at the AST layer.

                Canonical recipe (the only supported input shape):

                  git diff -W -U99999 origin/main...HEAD | watson blastradius

                `-W` keeps each touched function whole inside the hunk; `-U99999`
                pads context so the hunk carries the entire file. watson
                reconstructs old + new in-memory and AST-diffs them per
                Class::method. watson does NOT shell out to git itself — the
                caller picks the diff source.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startDir = (string) ($input->getOption('project') ?? (getcwd() ?: '.'));
        $project = ProjectDetector::detect($startDir);

        $changes = self::readChangedSymbols($project->rootPath, $output);
        if ($changes === null) {
            return self::INVALID;
        }

        $base = $input->getOption('base');
        $head = $input->getOption('head');
        $envelope = new Envelope(
            language: 'php',
            framework: $project->framework->value,
            rootPath: $project->rootPath,
            base: is_string($base) && $base !== '' ? $base : null,
            head: is_string($head) && $head !== '' ? $head : null,
        );

        $entryPoints = EntrypointResolver::collect($project, [
            'scope' => (string) $input->getOption('scope'),
            'app_env' => (string) $input->getOption('app-env'),
        ]);

        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                '<comment>watson: %d entry points · %d changed symbols</comment>',
                count($entryPoints),
                count($changes),
            ));
        }

        $classLoader = self::loadConsumerClassLoader($project->rootPath);
        $maxDepth    = max(0, (int) $input->getOption('max-depth'));
        Blastradius::run($envelope, $project->rootPath, $changes, $entryPoints, $classLoader, $maxDepth);

        $output->write(Renderer::render((string) $input->getOption('format'), $envelope));

        return self::SUCCESS;
    }

    /**
     * @return list<\Watson\Core\Diff\ChangedSymbol>|null
     *         null = caller error (usage hint already printed),
     *         list = the parsed symbol set (possibly empty).
     */
    private static function readChangedSymbols(string $projectRoot, OutputInterface $output): ?array
    {
        $stdin = defined('STDIN') ? STDIN : fopen('php://stdin', 'r');
        if (!is_resource($stdin) || (function_exists('stream_isatty') && @stream_isatty($stdin))) {
            self::printUsage($output);
            return null;
        }
        return ChangedFilesReader::readUnifiedDiffSymbols($stdin, $projectRoot);
    }

    /**
     * Load the consumer's Composer autoloader without registering it (so we
     * don't perturb watson's own runtime). `vendor/autoload.php` returns the
     * `ClassLoader` instance after `composer-runtime-api` >= 2.0 — that's
     * what we use to map FQN → file at native Composer speed.
     */
    private static function loadConsumerClassLoader(string $projectRoot): ?ClassLoader
    {
        $autoload = $projectRoot . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return null;
        }
        $loader = require $autoload;
        return $loader instanceof ClassLoader ? $loader : null;
    }

    private static function printUsage(OutputInterface $output): void
    {
        $output->writeln('<error>watson blastradius needs a unified diff on stdin.</error>');
        $output->writeln('');
        $output->writeln('Pipe `git diff -W -U99999` output in:');
        $output->writeln('  git diff -W -U99999 origin/main...HEAD | watson blastradius');
    }
}
