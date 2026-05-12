<?php

declare(strict_types=1);

namespace Watson\Core\Output;

/**
 * Outcome of a single {@see \Watson\Cli\Source\EntrypointSource} invocation
 * inside {@see \Watson\Cli\ChainedEntrypointResolver}.
 *
 * - `Skipped` — the source's `canHandle()` returned false; no work done.
 * - `Ran`     — `collect()` completed; contribution may be zero or more.
 * - `Failed`  — `collect()` threw; contribution dropped, error captured.
 */
enum SourceRunStatus: string
{
    case Skipped = 'skipped';
    case Ran     = 'ran';
    case Failed  = 'failed';
}
