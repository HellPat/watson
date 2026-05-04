<?php

declare(strict_types=1);

namespace Watson\Core\Analysis;

use Watson\Cli\Reflection\StaticReflector;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Output\Envelope;
use Watson\Core\Reach\FileLevelReach;
use Watson\Core\Reach\TransitiveReach;

/**
 * Build a blastradius analysis result from a list of runtime entry points
 * and a list of changed files. Two reach passes are merged:
 *
 * 1. {@see FileLevelReach} — the entry point's own handler file is in the
 *    diff (high precision, low recall).
 * 2. {@see TransitiveReach} — the entry point's class transitively
 *    references at least one file in the diff (high recall, modest
 *    precision; catches services edited "behind" a controller/job).
 *
 * Both inputs come from the caller — entry points from the framework
 * adapters, changed files from `Watson\Core\Diff\ChangedFilesReader`.
 * watson does not shell out to git.
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
        ?StaticReflector $reflector = null,
    ): void {
        $directHits = array_fill_keys(
            FileLevelReach::affectedIndices($entryPoints, $changedFiles),
            true,
        );

        $transitiveHits = [];
        if ($reflector !== null) {
            $transitiveHits = array_fill_keys(
                TransitiveReach::affectedIndices($entryPoints, $changedFiles, $reflector, $projectRoot),
                true,
            );
        }

        $affected = [];
        foreach ($entryPoints as $idx => $ep) {
            $isDirect     = isset($directHits[$idx]);
            $isTransitive = isset($transitiveHits[$idx]);
            if (!$isDirect && !$isTransitive) {
                continue;
            }
            $affected[] = [
                'kind' => $ep->kind,
                'name' => $ep->name,
                'handler' => [
                    'fqn' => $ep->handlerFqn,
                    'path' => self::relativise($ep->handlerPath, $projectRoot),
                    'line' => $ep->handlerLine,
                ],
                'extra' => $ep->extra ?? null,
                'min_confidence' => $isDirect ? 'NameOnly' : 'Transitive',
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
