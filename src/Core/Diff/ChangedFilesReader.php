<?php

declare(strict_types=1);

namespace Watson\Core\Diff;

/**
 * Read the set of changed symbols from one of three callable shapes:
 *
 *   - newline-separated paths on stdin (`git diff --name-only … | watson …`)
 *     → file-level {@see ChangedSymbol}s (no method granularity).
 *   - a unified diff on stdin (`git diff -W -U99999 … | watson … --unified-diff`)
 *     → AST-diffed method-level symbols via {@see AstDiffMapper}.
 *   - explicit `--files=` flag values → file-level symbols.
 *
 * Watson never shells out to git itself — this reader is the only seam
 * between the upstream diff source and the blastradius engine.
 *
 * Recipe for full precision (single canonical path):
 *
 *     git diff -W -U99999 <ref> | watson blastradius --unified-diff
 *
 * `-W` keeps each changed method whole inside the hunk; `-U99999` makes
 * the hunk carry the full file so watson can reconstruct both halves in
 * memory and AST-diff them. Comment-only and whitespace-only edits are
 * dropped at this layer.
 */
final class ChangedFilesReader
{
    /**
     * Read a unified diff (must be `git diff -W -U99999`) into a list of
     * method-level {@see ChangedSymbol}s. Whitespace-only and comment-only
     * edits inside method bodies are dropped via AST hashing.
     *
     * @param resource $stream
     * @return list<ChangedSymbol>
     */
    public static function readUnifiedDiffSymbols($stream, string $projectRoot): array
    {
        $diff = stream_get_contents($stream);
        if (!is_string($diff) || $diff === '') {
            return [];
        }

        return AstDiffMapper::map($diff, $projectRoot);
    }

    /**
     * Wrap a list of file paths as file-level {@see ChangedSymbol}s
     * (`classFqn = null`, `methodName = null`). Used when the caller has no
     * diff to give (name-only mode, `--files=` flag).
     *
     * @param list<string> $paths absolute paths
     * @return list<ChangedSymbol>
     */
    public static function fileLevelSymbols(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            if ($p === '') {
                continue;
            }
            $out[] = new ChangedSymbol($p, null, null, 1, 1);
        }

        return $out;
    }

    /**
     * Newline-list parser. Empty lines and `#`-prefixed comments are
     * skipped — consistent with git porcelain (`git status --porcelain`,
     * `.gitignore`, …).
     *
     * @param resource $stream
     * @return list<string> absolute paths
     */
    public static function readNameOnly($stream, string $projectRoot): array
    {
        $out = [];
        while (($line = fgets($stream)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            $out[] = self::absolutise($trimmed, $projectRoot);
        }

        return self::dedupe($out);
    }

    /**
     * Unified-diff parser. Pulls the post-image filename from each
     * `+++ b/<path>` (or `+++ <path>` under `git diff --no-prefix`) header,
     * skips `/dev/null` (deletions), and dedupes — a single rename produces
     * two `+++` lines for the same file in some git versions.
     *
     * @param resource $stream
     * @return list<string> absolute paths
     */
    public static function readUnifiedDiff($stream, string $projectRoot): array
    {
        $out = [];
        while (($line = fgets($stream)) !== false) {
            if (!str_starts_with($line, '+++ ')) {
                continue;
            }
            $path = self::stripPlusPlusHeader($line);
            if ($path === null) {
                continue;
            }
            $out[] = self::absolutise($path, $projectRoot);
        }

        return self::dedupe($out);
    }

    /**
     * `--files=` flag values. Symfony Console gives us a list<string>
     * (repeatable option). Each value may itself be a comma-separated
     * list, for ergonomics.
     *
     * @param list<string> $values
     * @return list<string> absolute paths
     */
    public static function readFromFlag(array $values, string $projectRoot): array
    {
        $out = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $part) {
                $trimmed = trim($part);
                if ($trimmed === '') {
                    continue;
                }
                $out[] = self::absolutise($trimmed, $projectRoot);
            }
        }

        return self::dedupe($out);
    }

    private static function stripPlusPlusHeader(string $line): ?string
    {
        // `+++ b/path/to/file` (default) or `+++ path/to/file` (--no-prefix)
        // or `+++ /dev/null` (deletion). Trim trailing tab+timestamp some
        // diff producers append (`+++ b/x.php\t2026-01-01 …`).
        $rest = rtrim(substr($line, 4));
        $tabAt = strpos($rest, "\t");
        if ($tabAt !== false) {
            $rest = substr($rest, 0, $tabAt);
        }
        if ($rest === '' || $rest === '/dev/null') {
            return null;
        }
        if (str_starts_with($rest, 'b/')) {
            return substr($rest, 2);
        }
        if (str_starts_with($rest, 'a/')) {
            // `+++ a/...` shouldn't occur in well-formed diffs, but be lenient.
            return substr($rest, 2);
        }

        return $rest;
    }

    private static function absolutise(string $path, string $projectRoot): string
    {
        if ($path === '') {
            return '';
        }
        if ($path[0] === DIRECTORY_SEPARATOR || (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:[\\\\\/]/', $path))) {
            return $path;
        }

        return rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private static function dedupe(array $paths): array
    {
        $seen = [];
        $out = [];
        foreach ($paths as $p) {
            if ($p === '' || isset($seen[$p])) {
                continue;
            }
            $seen[$p] = true;
            $out[] = $p;
        }

        return $out;
    }
}
