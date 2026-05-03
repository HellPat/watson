<?php

declare(strict_types=1);

namespace Watson\Core\Diff;

use Symfony\Component\Process\Process;

/**
 * Resolved diff specification — base revision, head reference, and a flag
 * telling the diff runner whether to compare against the index, the working
 * tree, or another commit.
 *
 * Mirrors the Rust `git/spec.rs` shapes one-for-one so the CLI surface
 * stays identical:
 *
 *   (no revisions)      working tree vs HEAD
 *   --cached            index vs HEAD
 *   <rev>               working tree vs <rev>
 *   <a> <b>             <a> vs <b>
 *   <a>..<b>            same as `<a> <b>`
 *   <a>...<b>           merge-base(a, b) vs <b>
 */
final class DiffSpec
{
    public function __construct(
        public readonly string $baseSha,
        public readonly HeadKind $head,
        public readonly string $baseDisplay,
        public readonly string $headDisplay,
    ) {
    }

    /**
     * @param list<string> $revisions
     */
    public static function resolve(string $repoPath, array $revisions, bool $cached): self
    {
        if ($cached && $revisions !== []) {
            throw new \InvalidArgumentException('--cached / --staged cannot be combined with explicit revisions');
        }
        if ($cached) {
            $head = self::revParse($repoPath, 'HEAD');

            return new self($head, HeadKind::index(), 'HEAD', '<index>');
        }

        return match (count($revisions)) {
            0 => new self(
                self::revParse($repoPath, 'HEAD'),
                HeadKind::workingTree(),
                'HEAD',
                '<working tree>',
            ),
            1 => self::resolveSingle($repoPath, $revisions[0]),
            2 => self::resolveTwo($repoPath, $revisions[0], $revisions[1]),
            default => throw new \InvalidArgumentException('too many positional revisions (max 2)'),
        };
    }

    private static function resolveSingle(string $repo, string $rev): self
    {
        if (str_contains($rev, '...')) {
            [$a, $b] = explode('...', $rev, 2);
            if ($a === '' || $b === '') {
                throw new \InvalidArgumentException("Invalid syntax: expected <a>...<b>, got {$rev}");
            }
            $base = self::gitMergeBase($repo, $a, $b);
            $headSha = self::revParse($repo, $b);

            return new self($base, HeadKind::commit($headSha), "merge-base({$a},{$b})", $b);
        }
        if (str_contains($rev, '..')) {
            [$a, $b] = explode('..', $rev, 2);
            if ($a === '' || $b === '') {
                throw new \InvalidArgumentException("Invalid syntax: expected <a>..<b>, got {$rev}");
            }
            $baseSha = self::revParse($repo, $a);
            $headSha = self::revParse($repo, $b);

            return new self($baseSha, HeadKind::commit($headSha), $a, $b);
        }

        // Plain <rev>: working tree vs <rev>.
        $baseSha = self::revParse($repo, $rev);

        return new self($baseSha, HeadKind::workingTree(), $rev, '<working tree>');
    }

    private static function resolveTwo(string $repo, string $a, string $b): self
    {
        if (str_contains($a, '..') || str_contains($b, '..')) {
            throw new \InvalidArgumentException(
                'two positional revisions cannot themselves contain `..` / `...`. Use either `<a> <b>` OR `<a>..<b>`, not both'
            );
        }
        $baseSha = self::revParse($repo, $a);
        $headSha = self::revParse($repo, $b);

        return new self($baseSha, HeadKind::commit($headSha), $a, $b);
    }

    public function assertHeadMatchesWorkingTree(string $repo): void
    {
        if ($this->head->kind === 'commit') {
            $headSha = self::revParse($repo, 'HEAD');
            if ($this->head->sha !== $headSha) {
                throw new \RuntimeException(sprintf(
                    "head revision %s does not match HEAD (%s). Watson reads files from on-disk \n"
                        . "and so the head side of the diff must equal the current working tree.\n"
                        . "Run `git checkout %s` first, or use a working-tree comparison instead.",
                    $this->headDisplay,
                    substr($headSha, 0, 7),
                    $this->headDisplay,
                ));
            }
        }
    }

    private static function revParse(string $repo, string $rev): string
    {
        $process = new Process(['git', '-C', $repo, 'rev-parse', '--verify', $rev]);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException("git rev-parse {$rev} failed: " . trim($process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }

    private static function gitMergeBase(string $repo, string $a, string $b): string
    {
        $process = new Process(['git', '-C', $repo, 'merge-base', $a, $b]);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException("git merge-base {$a} {$b} failed: " . trim($process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }
}
