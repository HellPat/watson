<?php

declare(strict_types=1);

namespace Watson\Core\Diff;

/**
 * What the diff is comparing AGAINST. Three shapes mirror `git diff`:
 *   - `commit` — a fixed tree (any SHA / branch / tag).
 *   - `workingTree` — on-disk content (uncommitted edits included).
 *   - `index` — the staged content (`--cached` / `--staged`).
 *
 * Implemented as a tagged value object rather than an enum because the
 * commit variant carries a SHA payload.
 */
final class HeadKind
{
    public const WorkingTree = 'working-tree';
    public const Index = 'index';
    public const Commit = 'commit';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $sha = null,
    ) {
    }

    public static function workingTree(): self
    {
        return new self(self::WorkingTree);
    }

    public static function index(): self
    {
        return new self(self::Index);
    }

    public static function commit(string $sha): self
    {
        return new self(self::Commit, $sha);
    }
}
