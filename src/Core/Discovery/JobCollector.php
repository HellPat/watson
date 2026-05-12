<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Yields one `laravel.job` {@see EntryPoint} per concrete class
 * implementing `Illuminate\Contracts\Queue\ShouldQueue` in the given
 * directory.
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
        foreach ($index->concreteSubclassesOf(self::SHOULD_QUEUE_FQN) as $entry) {
            $handle = $entry->methods['handle'] ?? null;
            $out[] = new EntryPoint(
                kind: 'laravel.job',
                name: $entry->fqn,
                handlerFqn: $handle !== null ? $entry->fqn . '::handle' : $entry->fqn,
                handlerPath: $entry->file,
                handlerLine: $handle?->startLine ?: $entry->startLine,
                source: Source::Interface_,
            );
        }
        return $out;
    }
}
