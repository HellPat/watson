<?php

declare(strict_types=1);

namespace Watson\Core\Diff;

/**
 * Single-purpose reader for the canonical watson input contract:
 *
 *   git diff -W -U99999 <ref> | watson blastradius
 *
 * Returns a list of method-level {@see ChangedSymbol}s after running the
 * diff through {@see AstDiffMapper}. Whitespace-only and comment-only
 * edits inside method bodies are dropped here before the reach engine
 * ever sees them.
 *
 * Looser modes (name-only stdin, `--files=` flags) used to live next to
 * this method; they were removed so the engine has exactly one input
 * shape to support.
 */
final class ChangedFilesReader
{
    /**
     * @param resource $stream
     * @param int      $stripSegments leading path segments to drop from each
     *                                diff entry — matches `patch -pN`. `0`
     *                                = no stripping; `1` drops one segment
     *                                (e.g. `backend/app/Foo.php` →
     *                                `app/Foo.php`).
     * @return list<ChangedSymbol>
     */
    public static function readUnifiedDiffSymbols($stream, string $projectRoot, int $stripSegments = 0): array
    {
        $diff = stream_get_contents($stream);
        if (!is_string($diff) || $diff === '') {
            return [];
        }
        return AstDiffMapper::map($diff, $projectRoot, $stripSegments);
    }
}
