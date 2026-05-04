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
use Watson\Core\Analysis\Blastradius;
use Watson\Core\Diff\ChangedFilesReader;
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
            ->setHelp(<<<HELP
                Reads the list of changed files from stdin (one path per line) and reports which framework entry points reach those files.

                Examples:
                  git diff --name-only origin/main...HEAD | watson blastradius
                  git diff --cached --name-only             | watson blastradius
                  git diff origin/main...HEAD                | watson blastradius --unified-diff
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
        $changedFiles = self::collectChangedFiles($project->rootPath, $filesFlag, $unifiedDiff, $output);
        if ($changedFiles === null) {
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

        $eps = EntrypointResolver::collect($project, [
            'scope' => (string) $input->getOption('scope'),
            'app_env' => (string) $input->getOption('app-env'),
        ]);

        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                '<comment>watson: %d entry points · %d changed files</comment>',
                count($eps),
                count($changedFiles),
            ));
        }

        Blastradius::run($envelope, $project->rootPath, $changedFiles, $eps);

        $output->write(Renderer::render((string) $input->getOption('format'), $envelope));

        return self::SUCCESS;
    }

    /**
     * @param list<string> $filesFlag
     * @return list<string>|null  null = caller error (already printed); list = the changed-files set (possibly empty)
     */
    private static function collectChangedFiles(
        string $projectRoot,
        array $filesFlag,
        bool $unifiedDiff,
        OutputInterface $output,
    ): ?array {
        if ($filesFlag !== []) {
            return ChangedFilesReader::readFromFlag($filesFlag, $projectRoot);
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

        return $unifiedDiff
            ? ChangedFilesReader::readUnifiedDiff($stdin, $projectRoot)
            : ChangedFilesReader::readNameOnly($stdin, $projectRoot);
    }

    private static function printUsage(OutputInterface $output): void
    {
        $output->writeln('<error>watson blastradius needs a list of changed files on stdin.</error>');
        $output->writeln('');
        $output->writeln('Examples:');
        $output->writeln('  git diff --name-only origin/main...HEAD | watson blastradius');
        $output->writeln('  git diff origin/main...HEAD             | watson blastradius --unified-diff');
        $output->writeln('  watson blastradius --files=app/X.php --files=app/Y.php');
    }
}
