<?php

declare(strict_types=1);

namespace Watson\Core\Analysis;

use Composer\Autoload\ClassLoader;
use Watson\Core\Diff\ChangedSymbol;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Output\Envelope;
use Watson\Core\Reach\FileLevelReach;
use Watson\Core\Reach\TransitiveReach;

/**
 * Build a blastradius analysis result from a list of runtime entry points
 * and a list of {@see ChangedSymbol}s. Two reach passes are merged:
 *
 * 1. {@see FileLevelReach} — the entry point's own handler file holds one
 *    of the changed symbols (`direct` reach, high precision).
 * 2. {@see TransitiveReach} — the entry point's class transitively
 *    references at least one file containing a changed symbol
 *    (`indirect` reach, high recall, modest precision; catches services
 *    edited "behind" a controller / job / listener).
 *
 * Row shaping is delegated to {@see AffectedEntryPointBuilder} so this
 * class stays a thin orchestrator.
 */
final class Blastradius
{
    public const NAME = 'blastradius';
    public const VERSION = '0.4.0';

    /**
     * @param list<EntryPoint>     $entryPoints
     * @param list<ChangedSymbol>  $changes
     */
    public static function run(
        Envelope $envelope,
        string $projectRoot,
        array $changes,
        array $entryPoints,
        ?ClassLoader $classLoader = null,
        int $maxDepth = 3,
    ): void {
        $symbolsByFile  = self::groupByFile($changes);
        $directHits     = array_fill_keys(FileLevelReach::affectedIndices($entryPoints, $changes), true);
        $indirectHits   = $classLoader === null
            ? []
            : TransitiveReach::affectedIndices($entryPoints, $changes, $classLoader, $projectRoot, maxDepth: $maxDepth);

        $rows = (new AffectedEntryPointBuilder($projectRoot))
            ->build($entryPoints, $directHits, $indirectHits, $symbolsByFile);

        $envelope->pushAnalysis(self::NAME, self::VERSION, [
            'summary' => [
                'files_changed' => count($symbolsByFile),
                'symbols_changed' => count($changes),
                'entry_points_affected' => count($rows),
            ],
            'changed_symbols' => array_values(array_map(
                static fn (ChangedSymbol $c): array => $c->withRelativeFile($projectRoot)->jsonSerialize(),
                $changes,
            )),
            'affected_entry_points' => array_values($rows),
        ]);
    }

    /**
     * @param list<ChangedSymbol> $changes
     * @return array<string, list<ChangedSymbol>>
     */
    private static function groupByFile(array $changes): array
    {
        $by = [];
        foreach ($changes as $c) {
            $by[$c->filePath][] = $c;
            $real = realpath($c->filePath);
            if ($real !== false && $real !== $c->filePath) {
                $by[$real][] = $c;
            }
        }
        return $by;
    }
}
