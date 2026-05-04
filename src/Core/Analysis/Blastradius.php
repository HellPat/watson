<?php

declare(strict_types=1);

namespace Watson\Core\Analysis;

use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Output\Envelope;
use Watson\Core\Reach\FileLevelReach;

/**
 * Build a blastradius analysis result from a list of runtime entry points
 * and a list of changed files. Pure function on top of the file-level
 * reach algorithm — both inputs are supplied by the caller (the entry-
 * point list comes from the framework adapters; the changed-files list
 * comes from `Watson\Core\Diff\ChangedFilesReader`, which reads stdin or
 * `--files`). watson does not shell out to git.
 */
final class Blastradius
{
    public const NAME = 'blastradius';
    public const VERSION = '0.3.0';

    /**
     * @param list<EntryPoint> $entryPoints
     * @param list<string>     $changedFiles absolute paths
     */
    public static function run(
        Envelope $envelope,
        string $projectRoot,
        array $changedFiles,
        array $entryPoints,
    ): void {
        $affectedIndices = FileLevelReach::affectedIndices($entryPoints, $changedFiles);

        $affected = [];
        foreach ($affectedIndices as $idx) {
            $ep = $entryPoints[$idx];
            $affected[] = [
                'kind' => $ep->kind,
                'name' => $ep->name,
                'handler' => [
                    'fqn' => $ep->handlerFqn,
                    'path' => self::relativise($ep->handlerPath, $projectRoot),
                    'line' => $ep->handlerLine,
                ],
                'extra' => $ep->extra ?? null,
                'min_confidence' => 'NameOnly',
            ];
        }

        $envelope->pushAnalysis(self::NAME, self::VERSION, [
            'summary' => [
                'files_changed' => count($changedFiles),
                'entry_points_affected' => count($affected),
            ],
            'affected_entry_points' => array_values($affected),
        ]);
    }

    private static function relativise(string $path, string $root): string
    {
        $real = realpath($path);
        $rootReal = realpath($root) ?: $root;
        $candidate = $real !== false ? $real : $path;
        if (str_starts_with($candidate, $rootReal . DIRECTORY_SEPARATOR)) {
            return substr($candidate, strlen($rootReal) + 1);
        }

        return $path;
    }
}
