<?php

declare(strict_types=1);

namespace Watson\Core\Reach;

use Composer\Autoload\ClassLoader;
use Watson\Core\Diff\ChangedSymbol;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Reach\CallGraph\MethodResolver;
use Watson\Core\Reach\CallGraph\SymbolGraph;

/**
 * Symbol-level (`Class::method`) call-graph reach pass. Built on top of
 * {@see MethodResolver} which walks every entry-point handler file BFS
 * and emits per-method outgoing edges:
 *
 *   - `Foo::bar()` static call            → Resolved
 *   - `new Foo(...)`                      → Resolved (`Foo::__construct`)
 *   - `$x->bar()` with receiver typed via param / property / local
 *     `new` / declared return type        → Resolved (`<receiverFqn>::bar`)
 *   - `$x->bar()` with unresolved receiver→ NameOnly (`*::bar`)
 *   - `extends`, `implements`, `Foo::class`,
 *     `Foo::CONST`, type hints            → ClassLevel (`Foo::*`)
 *
 * Reverse reach matches each entry-point handler `Class::method` against
 * the set of changed symbols:
 *
 *   - `target` symbol is reached directly,
 *   - some Resolved edge upstream points to it,
 *   - a NameOnly edge `*::m` matches a changed method called `m` on any
 *     class,
 *   - a ClassLevel edge `Foo::*` matches any change inside `Foo`.
 *
 * Per affected entry point we return both the reach path (list of caller
 * symbols, handler first) and the triggering {@see ChangedSymbol}s.
 */
final class TransitiveReach
{
    private const MAX_FILES_DEFAULT = 5000;
    private const MAX_DEPTH_DEFAULT = 3;

    /**
     * @param list<EntryPoint>     $entryPoints
     * @param list<ChangedSymbol>  $changes
     * @return array<int, array{path: list<string>, triggers: list<ChangedSymbol>}>
     */
    public static function affectedIndices(
        array $entryPoints,
        array $changes,
        ClassLoader $classLoader,
        string $projectRoot,
        int $maxFiles = self::MAX_FILES_DEFAULT,
        int $maxDepth = self::MAX_DEPTH_DEFAULT,
    ): array {
        if ($entryPoints === [] || $changes === []) {
            return [];
        }

        $seeds = self::collectSeedFiles($entryPoints);
        if ($seeds === []) {
            return [];
        }

        $graph = MethodResolver::build($seeds, $classLoader, $projectRoot, $maxFiles);

        // Build the target-symbol set + matching ChangedSymbol[] for each.
        // - Class::method changes target one node.
        // - Class::* changes target a wildcard for that class.
        // - File-level changes (class=null, method=null) target every
        //   symbol defined in that file (best-effort via fileSymbols).
        /** @var array<string, list<ChangedSymbol>> $targetTriggers */
        $targetTriggers = [];
        /** @var array<string, list<ChangedSymbol>> $classLevelTargets class=>changes */
        $classLevelTargets = [];
        /** @var array<string, list<ChangedSymbol>> $methodNameTargets methodName => changes */
        $methodNameTargets = [];

        foreach ($changes as $cs) {
            if ($cs->classFqn !== null && $cs->methodName !== null) {
                $sym = $cs->classFqn . '::' . $cs->methodName;
                $targetTriggers[$sym][] = $cs;
                $classLevelTargets[$cs->classFqn][] = $cs;
                $methodNameTargets[$cs->methodName][] = $cs;
                continue;
            }
            if ($cs->classFqn !== null) {
                $classLevelTargets[$cs->classFqn][] = $cs;
                continue;
            }
            // File-level change — expand to every symbol the graph
            // recorded for this file.
            $absCandidates = [$cs->filePath];
            $real = realpath($cs->filePath);
            if ($real !== false) {
                $absCandidates[] = $real;
            }
            foreach ($absCandidates as $abs) {
                foreach ($graph->symbolsInFile($abs) as $sym) {
                    $targetTriggers[$sym][] = $cs;
                    [$cls, $tail] = self::split($sym);
                    if ($cls !== null) {
                        $classLevelTargets[$cls][] = $cs;
                        if ($tail !== null && $tail !== '*') {
                            $methodNameTargets[$tail][] = $cs;
                        }
                    }
                }
            }
        }

        // Reverse BFS — for each caller symbol, walk all callers that
        // emit an edge to ANY target. Carry the originating ChangedSymbol
        // through the chain.

        /** @var array<string, list<string>> $reverse callee → list of callers */
        $reverse = self::invertEdges($graph);

        /** @var array<string, list<ChangedSymbol>> $reachableTriggers */
        $reachableTriggers = [];
        /** @var array<string, string> $nextHop caller → next-hop towards a changed symbol */
        $nextHop = [];

        // Seed reachable with direct target hits.
        $queue = [];
        foreach ($targetTriggers as $sym => $trigs) {
            $reachableTriggers[$sym] = $trigs;
            $queue[] = $sym;
        }

        // Apply class-level + method-name target propagation in one pass
        // over the graph edges (cheaper than per-step BFS lookup).
        foreach ($graph->edges as $caller => $edges) {
            foreach ($edges as $edge) {
                $matchedTriggers = self::matchEdge($edge, $classLevelTargets, $methodNameTargets);
                if ($matchedTriggers === []) {
                    continue;
                }
                if (!isset($reachableTriggers[$caller])) {
                    $reachableTriggers[$caller] = $matchedTriggers;
                    $queue[] = $caller;
                } else {
                    $reachableTriggers[$caller] = self::mergeTriggers($reachableTriggers[$caller], $matchedTriggers);
                }
            }
        }

        // Standard reverse BFS over `caller -> callee` edges to propagate
        // reachability up the chain.
        $depth = [];
        foreach ($queue as $seed) {
            $depth[$seed] = 0;
        }
        while ($queue !== []) {
            $sym = array_shift($queue);
            $d = $depth[$sym] ?? 0;
            if ($d >= $maxDepth) {
                continue;
            }
            $callers = $reverse[$sym] ?? [];
            foreach ($callers as $caller) {
                if (isset($reachableTriggers[$caller])) {
                    // Already discovered via a shorter or equal path.
                    continue;
                }
                $reachableTriggers[$caller] = $reachableTriggers[$sym];
                $nextHop[$caller] = $sym;
                $depth[$caller] = $d + 1;
                $queue[] = $caller;
            }
        }

        // Map entry points to their `Class::method` graph symbol and
        // build the per-EP return record.
        $hits = [];
        foreach ($entryPoints as $idx => $ep) {
            $sym = $ep->handlerFqn;
            if ($sym === '' || strpos($sym, '::') === false) {
                continue;
            }
            if (!isset($reachableTriggers[$sym])) {
                continue;
            }
            $hits[$idx] = [
                'path'     => self::buildPath($sym, $nextHop),
                'triggers' => $reachableTriggers[$sym],
            ];
        }

        return $hits;
    }

    /**
     * @param array{target:string,kind:string} $edge
     * @param array<string, list<ChangedSymbol>> $classLevelTargets
     * @param array<string, list<ChangedSymbol>> $methodNameTargets
     * @return list<ChangedSymbol>
     */
    private static function matchEdge(array $edge, array $classLevelTargets, array $methodNameTargets): array
    {
        [$cls, $tail] = self::split($edge['target']);
        if ($cls === null) {
            return [];
        }

        $out = [];

        // Any edge pointing at a class with a class-level change target
        // ("anything in this class changed") matches — including Resolved
        // edges, because the resolved method *is* in the changed class.
        if ($cls !== '*' && isset($classLevelTargets[$cls])) {
            $out = self::mergeTriggers($out, $classLevelTargets[$cls]);
        }

        // NameOnly edge (`*::m`) — matches changes to a method called `m`
        // on ANY class. Bounded by method name.
        if ($cls === '*' && $tail !== null && $tail !== '*') {
            if (isset($methodNameTargets[$tail])) {
                $out = self::mergeTriggers($out, $methodNameTargets[$tail]);
            }
        }

        // Resolved edges where the target Class::method is itself a
        // specific changed symbol are picked up by the seed pass above;
        // nothing to add here for that case.
        return $out;
    }

    /**
     * @param list<ChangedSymbol> $a
     * @param list<ChangedSymbol> $b
     * @return list<ChangedSymbol>
     */
    private static function mergeTriggers(array $a, array $b): array
    {
        $seen = [];
        $out = [];
        foreach ([...$a, ...$b] as $cs) {
            $key = $cs->symbol() . '@' . $cs->filePath;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $cs;
        }
        return $out;
    }

    /**
     * @param array<string, string> $nextHop
     * @return list<string>
     */
    private static function buildPath(string $start, array $nextHop): array
    {
        $path = [$start];
        $cur  = $start;
        $seen = [$start => true];
        while (isset($nextHop[$cur])) {
            $cur = $nextHop[$cur];
            if (isset($seen[$cur])) {
                break;
            }
            $seen[$cur] = true;
            $path[] = $cur;
        }
        return $path;
    }

    /**
     * @return array<string, list<string>> callee → list of callers
     */
    private static function invertEdges(SymbolGraph $g): array
    {
        $reverse = [];
        foreach ($g->edges as $caller => $edges) {
            foreach ($edges as $e) {
                $reverse[$e['target']][] = $caller;
            }
        }
        return $reverse;
    }

    /**
     * @return array{0: ?string, 1: ?string} [classFqn, tail] where tail is
     *         either a method name, '*' for class-level, or null when the
     *         symbol cannot be split.
     */
    private static function split(string $sym): array
    {
        $sep = strrpos($sym, '::');
        if ($sep === false) {
            return [null, null];
        }
        $cls = substr($sym, 0, $sep);
        $tail = substr($sym, $sep + 2);
        return [$cls === '' ? null : $cls, $tail === '' ? null : $tail];
    }

    /**
     * @param list<EntryPoint> $entryPoints
     * @return list<string>
     */
    private static function collectSeedFiles(array $entryPoints): array
    {
        $seeds = [];
        foreach ($entryPoints as $ep) {
            if ($ep->handlerPath === '') {
                continue;
            }
            $real = realpath($ep->handlerPath);
            $key = $real !== false ? $real : $ep->handlerPath;
            $seeds[$key] = true;
        }
        return array_keys($seeds);
    }
}
