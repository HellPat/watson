<?php

declare(strict_types=1);

namespace Watson\Core\Output;

/**
 * One row of the per-run source report rendered in markdown + JSON
 * output. Populated by {@see \Watson\Cli\ChainedEntrypointResolver}.
 */
final class SourceStatus implements \JsonSerializable
{
    public function __construct(
        /** Stable source name, e.g. `symfony.routes`. Matches `EntryPoint::discoveredBy`. */
        public readonly string $name,
        public readonly SourceRunStatus $status,
        /** Number of entry points the source contributed (0 for skipped / failed). */
        public readonly int $count,
        /** Error message captured when `status === Failed`. */
        public readonly ?string $error = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        $out = [
            'name'   => $this->name,
            'status' => $this->status->value,
            'count'  => $this->count,
        ];
        if ($this->error !== null) {
            $out['error'] = $this->error;
        }

        return $out;
    }
}
