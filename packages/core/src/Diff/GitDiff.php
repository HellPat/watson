<?php

declare(strict_types=1);

namespace Watson\Core\Diff;

use Symfony\Component\Process\Process;

/**
 * Run `git diff --name-only` (file-level fallback shape) for the resolved
 * `DiffSpec`, returning the absolute paths of every file in the diff. The
 * head-kind tells us whether to add a second positional rev (commit), no
 * second arg (working tree), or `--cached` (index).
 *
 * git emits paths relative to the *git toplevel* (which can be a parent of
 * the watson root in monorepo layouts where `.git` lives above us). We
 * resolve them against the toplevel so the resulting absolute paths line
 * up with on-disk symbol locations.
 */
final class GitDiff
{
    /** @return list<string> absolute paths of files in the diff */
    public static function changedFiles(string $repoPath, DiffSpec $spec): array
    {
        $cmd = ['git', '-C', $repoPath, 'diff', '--name-only', '--no-renames'];
        match ($spec->head->kind) {
            HeadKind::Index => array_push($cmd, '--cached', $spec->baseSha),
            HeadKind::WorkingTree => array_push($cmd, $spec->baseSha),
            HeadKind::Commit => array_push($cmd, $spec->baseSha, $spec->head->sha ?? throw new \LogicException('commit head missing sha')),
        };

        $process = new Process($cmd);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('git diff failed: ' . trim($process->getErrorOutput()));
        }

        $toplevel = self::gitToplevel($repoPath);
        $relative = array_filter(
            array_map('trim', explode("\n", $process->getOutput())),
            static fn (string $line): bool => $line !== '',
        );

        return array_values(array_map(
            static fn (string $rel): string => $toplevel . DIRECTORY_SEPARATOR . $rel,
            $relative,
        ));
    }

    private static function gitToplevel(string $repoPath): string
    {
        $process = new Process(['git', '-C', $repoPath, 'rev-parse', '--show-toplevel']);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('git rev-parse --show-toplevel failed: ' . trim($process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }
}
