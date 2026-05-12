<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Static-discovery collector for Laravel queued jobs. Walks the
 * supplied directory and yields any concrete class implementing
 * `Illuminate\Contracts\Queue\ShouldQueue`. Pure AST via
 * {@see ClassIndex} — we never `require_once` user code or pay
 * BetterReflection's per-class inheritance-walk cost.
 */
final class JobCollector
{
    private const SHOULD_QUEUE_FQN = 'Illuminate\\Contracts\\Queue\\ShouldQueue';

    /** @return list<EntryPoint> */
    public static function collect(string $jobsDir): array
    {
        if (!is_dir($jobsDir)) {
            return [];
        }
        $index = ClassIndex::buildFromDirs([$jobsDir]);
        $out = [];
        foreach ($index->all() as $entry) {
            if ($entry->isAbstract || $entry->isInterface || $entry->isTrait) {
                continue;
            }
            if (!$index->isSubclassOf($entry->fqn, self::SHOULD_QUEUE_FQN)) {
                continue;
            }
            $handlerFqn = $entry->fqn;
            $line = $entry->startLine;
            $handle = $entry->methods['handle'] ?? null;
            if ($handle !== null) {
                $handlerFqn = $entry->fqn . '::handle';
                $line = $handle->startLine ?: $line;
            }
            $out[] = new EntryPoint(
                kind: 'laravel.job',
                name: $entry->fqn,
                handlerFqn: $handlerFqn,
                handlerPath: $entry->file,
                handlerLine: $line,
                source: Source::Interface_,
            );
        }
        return $out;
    }
}
