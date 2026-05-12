<?php

declare(strict_types=1);

namespace Watson\Core\Analysis;

use Watson\Core\Diff\ChangedSymbol;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Build the `affected_entry_points` payload that ships in the
 * blastradius analysis envelope.
 *
 * Lives next to {@see Blastradius} so the latter stays a thin
 * orchestrator: gather inputs → run two reach passes → delegate row
 * shaping to this builder.
 */
final class AffectedEntryPointBuilder
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @param list<EntryPoint>                                                       $entryPoints
     * @param array<int, true>                                                       $directHits  index → true
     * @param array<int, array{path: list<string>, triggers: list<ChangedSymbol>}>   $indirectHits index → reach record
     * @param array<string, list<ChangedSymbol>>                                     $symbolsByFile changed symbols keyed by absolute file (+ realpath alias)
     * @return list<array<string, mixed>>
     */
    public function build(array $entryPoints, array $directHits, array $indirectHits, array $symbolsByFile): array
    {
        $rows = [];
        foreach ($entryPoints as $idx => $entryPoint) {
            $row = $this->buildRow($idx, $entryPoint, $directHits, $indirectHits, $symbolsByFile);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @param array<int, true> $directHits
     * @param array<int, array{path: list<string>, triggers: list<ChangedSymbol>}> $indirectHits
     * @param array<string, list<ChangedSymbol>> $symbolsByFile
     * @return ?array<string, mixed>
     */
    private function buildRow(int $idx, EntryPoint $entryPoint, array $directHits, array $indirectHits, array $symbolsByFile): ?array
    {
        $isDirect   = isset($directHits[$idx]);
        $isIndirect = isset($indirectHits[$idx]);
        if (!$isDirect && !$isIndirect) {
            return null;
        }

        $triggers = $isDirect
            ? self::triggersForDirect($entryPoint, $symbolsByFile)
            : ($indirectHits[$idx]['triggers'] ?? []);

        $row = [
            'kind' => $entryPoint->kind,
            'name' => $entryPoint->name,
            'handler' => [
                'fqn'  => $entryPoint->handlerFqn,
                'path' => $this->relativise($entryPoint->handlerPath),
                'line' => $entryPoint->handlerLine,
            ],
            'extra' => $entryPoint->extra ?? null,
            'min_confidence' => $isDirect ? 'NameOnly' : 'Indirect',
            'triggered_by'   => $this->serializeTriggers($triggers),
        ];
        if (!$isDirect && isset($indirectHits[$idx]['path']) && count($indirectHits[$idx]['path']) > 1) {
            // Drop the entry-point's own symbol (already in `handler.fqn`)
            // and emit the chain of caller Class::method symbols.
            $row['reach_path'] = array_slice($indirectHits[$idx]['path'], 1);
        }
        return $row;
    }

    /**
     * Direct-hit triggers: any ChangedSymbol whose file matches the
     * handler's file. We don't filter by method here — a direct edit
     * to the handler file is by definition a direct hit.
     *
     * @param array<string, list<ChangedSymbol>> $symbolsByFile
     * @return list<ChangedSymbol>
     */
    private static function triggersForDirect(EntryPoint $entryPoint, array $symbolsByFile): array
    {
        $handlerReal = realpath($entryPoint->handlerPath);
        $key = $handlerReal !== false ? $handlerReal : $entryPoint->handlerPath;
        if (isset($symbolsByFile[$key])) {
            return $symbolsByFile[$key];
        }
        if ($handlerReal !== false && isset($symbolsByFile[$entryPoint->handlerPath])) {
            return $symbolsByFile[$entryPoint->handlerPath];
        }
        return [];
    }

    /**
     * @param list<ChangedSymbol> $triggers
     * @return list<array<string, mixed>>
     */
    private function serializeTriggers(array $triggers): array
    {
        $seen = [];
        $out = [];
        foreach ($triggers as $changedSymbol) {
            $key = $changedSymbol->symbol() . '@' . $changedSymbol->filePath;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $changedSymbol->withRelativeFile($this->projectRoot)->jsonSerialize();
        }
        return $out;
    }

    private function relativise(string $path): string
    {
        $real = realpath($path);
        $rootReal = realpath($this->projectRoot) ?: $this->projectRoot;
        $candidate = $real !== false ? $real : $path;
        if (str_starts_with($candidate, $rootReal . DIRECTORY_SEPARATOR)) {
            return substr($candidate, strlen($rootReal) + 1);
        }
        return $path;
    }
}
