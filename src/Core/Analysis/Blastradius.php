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
 * Both inputs come from the caller — entry points from the framework
 * adapters, changed symbols from `Watson\Core\Diff\ChangedFilesReader`
 * (which uses `AstDiffMapper` to drop comment-only / whitespace-only
 * edits before they ever reach this layer).
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
        $symbolsByFile = self::groupByFile($changes);

        $directHits = array_fill_keys(
            FileLevelReach::affectedIndices($entryPoints, $changes),
            true,
        );

        /** @var array<int, array{path: list<string>, triggers: list<ChangedSymbol>}> */
        $transitiveHits = [];
        if ($classLoader !== null) {
            $transitiveHits = TransitiveReach::affectedIndices(
                $entryPoints,
                $changes,
                $classLoader,
                $projectRoot,
                maxDepth: $maxDepth,
            );
        }

        $affected = [];
        foreach ($entryPoints as $idx => $ep) {
            $isDirect   = isset($directHits[$idx]);
            $isIndirect = isset($transitiveHits[$idx]);
            if (!$isDirect && !$isIndirect) {
                continue;
            }
            $triggers = $isDirect
                ? self::triggersForDirect($ep, $symbolsByFile)
                : ($transitiveHits[$idx]['triggers'] ?? []);
            $row = [
                'kind' => $ep->kind,
                'name' => $ep->name,
                'handler' => [
                    'fqn' => $ep->handlerFqn,
                    'path' => self::relativise($ep->handlerPath, $projectRoot),
                    'line' => $ep->handlerLine,
                ],
                'extra' => $ep->extra ?? null,
                'min_confidence' => $isDirect ? 'NameOnly' : 'Indirect',
                'triggered_by'   => self::serializeTriggers($triggers, $projectRoot),
            ];
            if (!$isDirect && isset($transitiveHits[$idx]['path']) && count($transitiveHits[$idx]['path']) > 1) {
                // Drop the entry-point's own symbol (already in `handler.fqn`)
                // and emit the chain of caller Class::method symbols.
                $row['reach_path'] = array_slice($transitiveHits[$idx]['path'], 1);
            }
            $affected[] = $row;
        }

        $envelope->pushAnalysis(self::NAME, self::VERSION, [
            'summary' => [
                'files_changed' => count($symbolsByFile),
                'symbols_changed' => count($changes),
                'entry_points_affected' => count($affected),
            ],
            'changed_symbols' => array_values(array_map(
                static fn (ChangedSymbol $c): array => array_merge(
                    $c->jsonSerialize(),
                    ['file' => self::relativise($c->filePath, $projectRoot)],
                ),
                $changes,
            )),
            'affected_entry_points' => array_values($affected),
        ]);
    }

    /**
     * Direct-hit triggers: any ChangedSymbol whose file matches the
     * handler's file. We don't filter by method here — a direct edit to
     * the handler file is by definition a direct hit.
     *
     * @param array<string, list<ChangedSymbol>> $symbolsByFile
     * @return list<ChangedSymbol>
     */
    private static function triggersForDirect(EntryPoint $ep, array $symbolsByFile): array
    {
        $handlerReal = realpath($ep->handlerPath);
        $key = $handlerReal !== false ? $handlerReal : $ep->handlerPath;
        if (isset($symbolsByFile[$key])) {
            return $symbolsByFile[$key];
        }
        if ($handlerReal !== false && isset($symbolsByFile[$ep->handlerPath])) {
            return $symbolsByFile[$ep->handlerPath];
        }
        return [];
    }

    /**
     * @param list<ChangedSymbol> $triggers
     * @return list<array{symbol:string,file:string,class:?string,method:?string}>
     */
    private static function serializeTriggers(array $triggers, string $projectRoot): array
    {
        $out = [];
        $seen = [];
        foreach ($triggers as $cs) {
            $key = $cs->symbol() . '@' . $cs->filePath;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'symbol' => $cs->symbol(),
                'file'   => self::relativise($cs->filePath, $projectRoot),
                'class'  => $cs->classFqn,
                'method' => $cs->methodName,
            ];
        }
        return $out;
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
