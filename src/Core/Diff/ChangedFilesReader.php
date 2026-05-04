<?php

declare(strict_types=1);

namespace Watson\Core\Diff;

/**
 * Read a list of changed files from one of three callable shapes:
 *
 *   - newline-separated paths on stdin (`git diff --name-only … | watson …`)
 *   - a unified diff on stdin (`git diff … | watson … --unified-diff`)
 *   - explicit `--files=` flag values
 *
 * Watson never shells out to git itself — this reader is the only seam
 * between the upstream diff source and the blastradius engine. All three
 * functions return absolute paths, resolved against `$projectRoot` for
 * any relative inputs, so downstream `FileLevelReach::affectedIndices()`
 * can compare on `realpath()` output regardless of where the caller ran
 * `git diff` from.
 */
final class ChangedFilesReader
{
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
