<?php

declare(strict_types=1);

namespace Watson\Cli;

use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\Source\ConsoleCommandsAstSource;
use Watson\Cli\Source\EntrypointSource;
use Watson\Cli\Source\LaravelCommandsRuntimeSource;
use Watson\Cli\Source\LaravelJobsSource;
use Watson\Cli\Source\LaravelRoutesSource;
use Watson\Cli\Source\PhpUnitTestsSource;
use Watson\Cli\Source\SymfonyCommandsRuntimeSource;
use Watson\Cli\Source\SymfonyMessageHandlersSource;
use Watson\Cli\Source\SymfonyRoutesSource;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Output\SourceRunStatus;
use Watson\Core\Output\SourceStatus;

/**
 * Run every {@see EntrypointSource} whose `canHandle()` returns true,
 * isolating failures so one broken source can't kill the rest.
 *
 * Returns the merged + de-duped list of entry points plus a per-source
 * status report ({@see SourceStatus}) that the renderer surfaces in
 * markdown + JSON output.
 *
 * Sources are registered in runtime-first order — when the same
 * `Class::method` handler is reachable via both a runtime adapter and
 * an AST scan, dedup keeps the runtime row (it usually carries richer
 * `extra` metadata like HTTP method / route path).
 */
final class ChainedEntrypointResolver
{
    /**
     * @param list<EntrypointSource> $sources
     */
    public function __construct(
        private readonly array $sources,
    ) {
    }

    public static function default(): self
    {
        return new self([
            new SymfonyRoutesSource(),
            new SymfonyCommandsRuntimeSource(),
            new SymfonyMessageHandlersSource(),
            new LaravelRoutesSource(),
            new LaravelCommandsRuntimeSource(),
            new LaravelJobsSource(),
            new PhpUnitTestsSource(),
            new ConsoleCommandsAstSource(),
        ]);
    }

    /**
     * @return array{entryPoints: list<EntryPoint>, sourceReport: list<SourceStatus>}
     */
    public function collect(Project $project, ResolverOptions $opts): array
    {
        $reflector = new StaticReflector($project->rootPath, null);

        $entryPoints = [];
        $report = [];

        foreach ($this->sources as $source) {
            $report[] = $this->runOne($source, $project, $reflector, $opts, $entryPoints);
        }

        return [
            'entryPoints'  => self::dedup($entryPoints),
            'sourceReport' => $report,
        ];
    }

    /**
     * @param list<EntryPoint> $entryPoints in-out — appends successful contributions
     */
    private function runOne(
        EntrypointSource $source,
        Project $project,
        StaticReflector $reflector,
        ResolverOptions $opts,
        array &$entryPoints,
    ): SourceStatus {
        if (!$source->canHandle($project)) {
            return new SourceStatus($source->name(), SourceRunStatus::Skipped, 0);
        }
        try {
            $contribution = $this->materialise($source->collect($project, $reflector, $opts));
        } catch (\Throwable $e) {
            return new SourceStatus($source->name(), SourceRunStatus::Failed, 0, $e->getMessage());
        }
        foreach ($contribution as $entryPoint) {
            $entryPoints[] = $entryPoint->withDiscoveredBy($source->name());
        }
        return new SourceStatus($source->name(), SourceRunStatus::Ran, count($contribution));
    }

    /**
     * @param iterable<EntryPoint> $iter
     * @return list<EntryPoint>
     */
    private function materialise(iterable $iter): array
    {
        return is_array($iter) ? array_values($iter) : iterator_to_array($iter, false);
    }

    /**
     * De-dup by `(kind, handlerFqn)`. Earlier-source rows win — the
     * default ordering puts runtime sources before AST scans so the
     * runtime row's richer metadata is preserved.
     *
     * @param list<EntryPoint> $entryPoints
     * @return list<EntryPoint>
     */
    private static function dedup(array $entryPoints): array
    {
        $seen = [];
        $out = [];
        foreach ($entryPoints as $entryPoint) {
            $key = $entryPoint->kind . '::' . $entryPoint->handlerFqn;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $entryPoint;
        }
        return $out;
    }
}
