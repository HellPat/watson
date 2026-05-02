<?php

declare(strict_types=1);

namespace Watson\Core\Analysis;

use Watson\Core\Diff\DiffSpec;
use Watson\Core\Diff\GitDiff;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Output\Envelope;
use Watson\Core\Reach\FileLevelReach;

/**
 * Build a blastradius analysis result from a list of runtime entry points
 * and a resolved `DiffSpec`. Pure function on top of the file-level reach
 * algorithm — the entry-point list is supplied by the caller (Laravel
 * adapter pulls it from the router; Symfony adapter from
 * `RouterInterface`).
 */
final class Blastradius
{
    public const NAME = 'blastradius';
    public const VERSION = '0.2.0-dev';

    /**
     * @param list<EntryPoint> $entryPoints
     */
    public static function run(
        Envelope $envelope,
        string $repoPath,
        DiffSpec $spec,
        array $entryPoints,
    ): void {
        $spec->assertHeadMatchesWorkingTree($repoPath);

        $changedFiles = GitDiff::changedFiles($repoPath, $spec);
        $affectedIndices = FileLevelReach::affectedIndices($entryPoints, $changedFiles);

        $affected = [];
        foreach ($affectedIndices as $idx) {
            $ep = $entryPoints[$idx];
            $affected[] = [
                'kind' => $ep->kind,
                'name' => $ep->name,
                'handler' => [
                    'fqn' => $ep->handlerFqn,
                    'path' => self::relativise($ep->handlerPath, $repoPath),
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
