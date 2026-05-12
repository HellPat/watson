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

        $graph   = MethodResolver::build($seeds, $classLoader, $projectRoot, $maxFiles);
        $targets = self::buildTargetIndex($changes, $graph);
        [$reachable, $queue] = self::seedReachable($targets);
        self::propagateClassAndNameOnlyEdges($graph, $targets, $reachable, $queue);
        $nextHop = self::reverseBfs($graph, $reachable, $queue, $maxDepth);

        return self::mapEntryPoints($entryPoints, $reachable, $nextHop);
    }

    /**
     * Translate the diff's ChangedSymbol set into three lookup tables
     * keyed by graph identity. Done once up-front so the reverse pass
     * is a cheap hashmap probe per edge.
     *
     * @param list<ChangedSymbol> $changes
     * @return array{
     *     exact: array<string, list<ChangedSymbol>>,
     *     classLevel: array<string, list<ChangedSymbol>>,
     *     methodName: array<string, list<ChangedSymbol>>,
     * }
     */
    private static function buildTargetIndex(array $changes, SymbolGraph $graph): array
    {
        $exact = [];
        $classLevel = [];
        $methodName = [];

        foreach ($changes as $changedSymbol) {
            if ($changedSymbol->classFqn !== null && $changedSymbol->methodName !== null) {
                $sym = $changedSymbol->classFqn . '::' . $changedSymbol->methodName;
                $exact[$sym][] = $changedSymbol;
                $classLevel[$changedSymbol->classFqn][] = $changedSymbol;
                $methodName[$changedSymbol->methodName][] = $changedSymbol;
                continue;
            }
            if ($changedSymbol->classFqn !== null) {
                $classLevel[$changedSymbol->classFqn][] = $changedSymbol;
                continue;
            }
            // File-level change — expand to every symbol the graph
            // recorded for this file.
            foreach (self::resolveFileSymbols($changedSymbol->filePath, $graph) as $sym) {
                $exact[$sym][] = $changedSymbol;
                [$cls, $tail] = self::split($sym);
                if ($cls !== null) {
                    $classLevel[$cls][] = $changedSymbol;
                    if ($tail !== null && $tail !== '*') {
                        $methodName[$tail][] = $changedSymbol;
                    }
                }
            }
        }

        return ['exact' => $exact, 'classLevel' => $classLevel, 'methodName' => $methodName];
    }

    /**
     * @return list<string>
     */
    private static function resolveFileSymbols(string $filePath, SymbolGraph $graph): array
    {
        $candidates = [$filePath];
        $real = realpath($filePath);
        if ($real !== false) {
            $candidates[] = $real;
        }
        $out = [];
        foreach ($candidates as $abs) {
            foreach ($graph->symbolsInFile($abs) as $sym) {
                $out[$sym] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Seed the reachable map with direct (exact) target hits.
     *
     * @param array{exact: array<string, list<ChangedSymbol>>, classLevel: array<string, list<ChangedSymbol>>, methodName: array<string, list<ChangedSymbol>>} $targets
     * @return array{0: array<string, list<ChangedSymbol>>, 1: list<string>}
     */
    private static function seedReachable(array $targets): array
    {
        $reachable = [];
        $queue = [];
        foreach ($targets['exact'] as $sym => $triggers) {
            $reachable[$sym] = $triggers;
            $queue[] = $sym;
        }
        return [$reachable, $queue];
    }

    /**
     * Single linear pass over every graph edge: when an edge's target
     * class is in the changed-class set, or its NameOnly suffix matches
     * a changed method name, propagate the originating trigger(s) onto
     * the caller. Cheaper than running this check per BFS step.
     *
     * @param array{exact: array<string, list<ChangedSymbol>>, classLevel: array<string, list<ChangedSymbol>>, methodName: array<string, list<ChangedSymbol>>} $targets
     * @param array<string, list<ChangedSymbol>> $reachable in-out
     * @param list<string> $queue in-out
     */
    private static function propagateClassAndNameOnlyEdges(SymbolGraph $graph, array $targets, array &$reachable, array &$queue): void
    {
        foreach ($graph->edges as $caller => $edges) {
            foreach ($edges as $edge) {
                $matched = self::matchEdge($edge, $targets['classLevel'], $targets['methodName']);
                if ($matched === []) {
                    continue;
                }
                if (!isset($reachable[$caller])) {
                    $reachable[$caller] = $matched;
                    $queue[] = $caller;
                } else {
                    $reachable[$caller] = self::mergeTriggers($reachable[$caller], $matched);
                }
            }
        }
    }

    /**
     * Standard reverse-BFS over `caller -> callee` edges. Each newly
     * discovered caller inherits the triggers of the callee it reaches.
     *
     * @param array<string, list<ChangedSymbol>> $reachable in-out
     * @param list<string> $queue in-out
     * @return array<string, string> caller → next-hop callee (for path reconstruction)
     */
    private static function reverseBfs(SymbolGraph $graph, array &$reachable, array &$queue, int $maxDepth): array
    {
        $reverse = self::invertEdges($graph);
        $depth = [];
        foreach ($queue as $seed) {
            $depth[$seed] = 0;
        }
        $nextHop = [];
        while ($queue !== []) {
            $sym = array_shift($queue);
            $d = $depth[$sym] ?? 0;
            if ($d >= $maxDepth) {
                continue;
            }
            foreach ($reverse[$sym] ?? [] as $caller) {
                if (isset($reachable[$caller])) {
                    continue; // already discovered via a shorter or equal path
                }
                $reachable[$caller] = $reachable[$sym];
                $nextHop[$caller] = $sym;
                $depth[$caller] = $d + 1;
                $queue[] = $caller;
            }
        }
        return $nextHop;
    }

    /**
     * @param list<EntryPoint> $entryPoints
     * @param array<string, list<ChangedSymbol>> $reachable
     * @param array<string, string> $nextHop
     * @return array<int, array{path: list<string>, triggers: list<ChangedSymbol>}>
     */
    private static function mapEntryPoints(array $entryPoints, array $reachable, array $nextHop): array
    {
        $hits = [];
        foreach ($entryPoints as $idx => $entryPoint) {
            $sym = $entryPoint->handlerFqn;
            if ($sym === '' || strpos($sym, '::') === false) {
                continue;
            }
            if (!isset($reachable[$sym])) {
                continue;
            }
            $hits[$idx] = [
                'path'     => self::buildPath($sym, $nextHop),
                'triggers' => $reachable[$sym],
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
        foreach ([...$a, ...$b] as $changedSymbol) {
            $key = $changedSymbol->symbol() . '@' . $changedSymbol->filePath;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $changedSymbol;
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
        foreach ($entryPoints as $entryPoint) {
            if ($entryPoint->handlerPath === '') {
                continue;
            }
            $real = realpath($entryPoint->handlerPath);
            $key = $real !== false ? $real : $entryPoint->handlerPath;
            $seeds[$key] = true;
        }
        return array_keys($seeds);
    }
}
