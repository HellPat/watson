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
use Watson\Core\Diff\ChangedSymbol;
use Watson\Core\Output\Envelope;
use Watson\Core\Output\Renderer;

#[AsCommand(
    name: 'blastradius',
    description: 'Report which routes, commands, jobs, and listeners are reached by a list of changed files (read from stdin, or --files=).',
)]
final class BlastradiusCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('files', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Explicit file path (repeatable, or comma-separated). Bypasses stdin.')
            ->addOption('unified-diff', null, InputOption::VALUE_NONE, 'Parse stdin as a unified diff (e.g. `git diff …`) instead of a newline-separated path list.')
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Cosmetic label shown as the diff base in the rendered output (e.g. "main")')
            ->addOption('head', null, InputOption::VALUE_REQUIRED, 'Cosmetic label shown as the diff head in the rendered output (e.g. "HEAD")')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project root (defaults to walking up from CWD)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text (human terminal), md (markdown for PRs/LLMs), json (machine), tok (tab-separated, token-optimized for LLM pipes)', 'text')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'routes (cheapest, runtime registry only) or all (adds commands / jobs / listeners / tests)', 'all')
            ->addOption('app-env', null, InputOption::VALUE_REQUIRED, 'APP_ENV passed to bin/console / artisan', 'dev')
            ->addOption('max-depth', null, InputOption::VALUE_REQUIRED, 'Maximum BFS hops the transitive-reach pass walks from any entry point. Lower = tighter signal, higher = more recall.', '3')
            ->setHelp(<<<HELP
                Reads the diff from stdin and reports which framework entry points reach the changed methods.

                Recommended (full method-level precision, drops comment-only / whitespace-only edits):
                  git diff -W -U99999 origin/main...HEAD | watson blastradius --unified-diff

                Coarser fallbacks (file-level only):
                  git diff --name-only origin/main...HEAD | watson blastradius
                  git diff --cached --name-only           | watson blastradius
                  watson blastradius --files=app/X.php --files=app/Y.php

                watson does NOT shell out to git itself. The caller picks the diff source.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startDir = (string) ($input->getOption('project') ?? (getcwd() ?: '.'));
        $project = ProjectDetector::detect($startDir);

        /** @var list<string> $filesFlag */
        $filesFlag = (array) $input->getOption('files');
        $unifiedDiff = (bool) $input->getOption('unified-diff');
        $changes = self::collectChangedSymbols($project->rootPath, $filesFlag, $unifiedDiff, $output);
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
     * @param list<string> $filesFlag
     * @return list<ChangedSymbol>|null  null = caller error (already printed); list = changed-symbol set (possibly empty)
     */
    private static function collectChangedSymbols(
        string $projectRoot,
        array $filesFlag,
        bool $unifiedDiff,
        OutputInterface $output,
    ): ?array {
        if ($filesFlag !== []) {
            return ChangedFilesReader::fileLevelSymbols(
                ChangedFilesReader::readFromFlag($filesFlag, $projectRoot),
            );
        }

        $stdin = defined('STDIN') ? STDIN : fopen('php://stdin', 'r');
        if (!is_resource($stdin)) {
            self::printUsage($output);

            return null;
        }
        if (function_exists('stream_isatty') && @stream_isatty($stdin)) {
            // Interactive shell — no pipe, no --files. Refuse silently
            // working-tree-vs-HEAD; ask the caller to be explicit.
            self::printUsage($output);

            return null;
        }

        if ($unifiedDiff) {
            return ChangedFilesReader::readUnifiedDiffSymbols($stdin, $projectRoot);
        }

        return ChangedFilesReader::fileLevelSymbols(
            ChangedFilesReader::readNameOnly($stdin, $projectRoot),
        );
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
        $output->writeln('<error>watson blastradius needs a diff on stdin.</error>');
        $output->writeln('');
        $output->writeln('Recommended (drops comment-only / whitespace-only edits):');
        $output->writeln('  git diff -W -U99999 origin/main...HEAD | watson blastradius --unified-diff');
        $output->writeln('');
        $output->writeln('Coarser fallbacks:');
        $output->writeln('  git diff --name-only origin/main...HEAD | watson blastradius');
        $output->writeln('  watson blastradius --files=app/X.php --files=app/Y.php');
    }
}
