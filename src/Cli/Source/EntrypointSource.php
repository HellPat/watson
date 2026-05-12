<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * One discovery strategy for a single kind of framework / runtime
 * entry point. The chain in
 * {@see \Watson\Cli\ChainedEntrypointResolver} runs every source whose
 * {@see canHandle()} returns true and isolates failures so one
 * broken source can't kill the rest.
 *
 * Implementations must keep `canHandle()` cheap — file-system checks
 * and a `composer.json` read at most. No subprocess spawning, no AST
 * walks. The orchestrator decides whether to call `collect()` based on
 * the cheap signal.
 */
interface EntrypointSource
{
    /**
     * Stable, dot-separated identifier. Used in both the per-run
     * {@see \Watson\Core\Output\SourceStatus} report and the
     * `EntryPoint::discoveredBy` tag, so reviewers can attribute every
     * row back to the source that emitted it.
     */
    public function name(): string;

    /**
     * Quick feasibility check. Must not shell out, must not parse PHP.
     * The chain calls this once per run before deciding whether to
     * invoke `collect()`.
     */
    public function canHandle(Project $project): bool;

    /**
     * Emit entry points discovered by this source. May yield zero or
     * more rows. Throwing is allowed — the chain catches the
     * exception and records the failure in the source report.
     *
     * @return iterable<EntryPoint>
     */
    public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable;
}
