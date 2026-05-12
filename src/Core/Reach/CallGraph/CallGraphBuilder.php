<?php

declare(strict_types=1);

namespace Watson\Core\Reach\CallGraph;

use Composer\Autoload\ClassLoader;

/**
 * BFS the project file graph starting from the entry-point seed files,
 * delegating per-file class indexing to {@see ClassIndexer} and per-method
 * body analysis to {@see MethodBodyAnalyzer}.
 *
 * Splits the work into three named phases so each one stays small and
 * single-responsibility:
 *
 *   1. discoverClasses() — BFS files, build the ClassMeta map.
 *   2. emitEdges()       — walk every method body, push edges into the
 *                          mutable edge index.
 *   3. SymbolGraph()     — wrap everything in the read-only graph VO.
 */
final class CallGraphBuilder
{
    /** @var array<string, ClassMeta-like array> */
    private array $classes = [];
    /** @var array<string, list<array{target:string,kind:string}>> */
    private array $edges = [];
    /** @var array<string, list<string>> */
    private array $fileSymbols = [];
    /** @var array<string, list<string>> */
    private array $methodIndex = [];
    /** @var list<string> visited project files, in discovery order */
    private array $visitedFiles = [];

    public function __construct(
        private readonly ClassIndexer $indexer,
        private readonly MethodBodyAnalyzer $analyzer,
        private readonly ClassLoader $classLoader,
        private readonly string $rootReal,
        private readonly string|false $vendorReal,
        private readonly int $maxFiles,
    ) {
    }

    /** @param list<string> $seedFiles */
    public function build(array $seedFiles): SymbolGraph
    {
        $this->discoverClasses($seedFiles);
        $this->emitEdges();
        return new SymbolGraph($this->classes, $this->edges, $this->fileSymbols, $this->methodIndex);
    }

    /**
     * Pass 1: BFS over project files. Each file is parsed once via
     * {@see ClassIndexer::indexFile()}; every class declared in it lands
     * in `$this->classes` and the file's symbol set in `$this->fileSymbols`.
     *
     * Follow-edges (class FQNs we want the BFS to expand into) come from
     * each class's parent, interfaces, traits, plus the type FQNs of
     * typed params / props / return types. That mirrors the v0 file-graph
     * frontier shape while keeping discovery method-aware.
     *
     * @param list<string> $seedFiles
     */
    private function discoverClasses(array $seedFiles): void
    {
        $queue = [];
        foreach ($seedFiles as $f) {
            $real = realpath($f);
            if ($real !== false && $this->insideProject($real)) {
                $queue[] = $real;
            }
        }

        $visited = [];
        while ($queue !== [] && count($visited) < $this->maxFiles) {
            $file = array_shift($queue);
            if (isset($visited[$file])) {
                continue;
            }
            $visited[$file] = true;

            $this->visitedFiles[] = $file;
            foreach ($this->indexer->indexFile($file) as $record) {
                $name = $record['name'];
                $meta = $record['meta'];
                $this->classes[$name] = $meta;
                $this->fileSymbols[$file][] = $name . '::*';
                foreach (array_keys($meta['methods']) as $m) {
                    $this->fileSymbols[$file][] = $name . '::' . $m;
                    $this->methodIndex[$m][] = $name . '::' . $m;
                }
                foreach ($this->frontierClassesOf($meta) as $follow) {
                    $resolved = $this->resolveFile($follow);
                    if ($resolved !== null && !isset($visited[$resolved])) {
                        $queue[] = $resolved;
                    }
                }
                // Bodies also reference classes (new Foo, Foo::bar, …) —
                // queue those files too so they're indexed before pass 2
                // tries to resolve their method signatures.
                foreach ($record['methodNodes'] as $methodNode) {
                    foreach ($this->bodyReferencedClasses($methodNode) as $follow) {
                        $resolved = $this->resolveFile($follow);
                        if ($resolved !== null && !isset($visited[$resolved])) {
                            $queue[] = $resolved;
                        }
                    }
                }
                // Drop the ASTs we just walked — Phase 2 re-parses the
                // file when it actually needs the method bodies, which
                // keeps memory flat on Laravel-sized closures (was
                // exhausting the 128 MB default before).
                unset($record);
            }
        }
    }

    /**
     * Pass 2: now that every class on the closure is indexed, walk every
     * method body and emit edges. Class-coupling edges (typed params,
     * return types) are emitted here too so a handler accepting `Foo $x`
     * with an empty body still couples to `Foo::*`.
     */
    /**
     * Phase 2: walk method bodies file-by-file, emitting edges. We
     * re-parse each visited file here so we never hold more than one
     * file's AST in memory at a time — earlier attempts kept the
     * methodNodes around in `$this->methodNodes`, which exhausted PHP's
     * default 128 MB heap on Laravel-sized closures.
     */
    private function emitEdges(): void
    {
        foreach ($this->visitedFiles as $file) {
            foreach ($this->indexer->indexFile($file) as $record) {
                $classFqn = $record['name'];
                $meta = $this->classes[$classFqn] ?? null;
                if ($meta === null) {
                    continue;
                }
                $this->emitClassEdges($classFqn, $meta, $record['methodNodes']);
            }
        }
    }

    /**
     * @param array<string, mixed>                                  $meta
     * @param array<string, \PhpParser\Node\Stmt\ClassMethod>       $methodNodes
     */
    private function emitClassEdges(string $classFqn, array $meta, array $methodNodes): void
    {
        foreach ($meta['methods'] as $methodName => $methodMeta) {
            $callerSym = $classFqn . '::' . $methodName;

            // Type-hint coupling — keep recall on empty bodies.
            foreach ($methodMeta['paramTypes'] as $t) {
                $this->edges[$callerSym][] = ['target' => $t . '::*', 'kind' => SymbolGraph::KIND_CLASS_LEVEL];
            }
            if ($methodMeta['returnType'] !== null) {
                $this->edges[$callerSym][] = ['target' => $methodMeta['returnType'] . '::*', 'kind' => SymbolGraph::KIND_CLASS_LEVEL];
            }

            $node = $methodNodes[$methodName] ?? null;
            if ($node === null) {
                continue;
            }
            $typeMap = new TypeMap($this->classes, $classFqn);
            foreach ($this->analyzer->analyze($classFqn, $node, $methodMeta['paramTypes'], $typeMap) as $edge) {
                $this->edges[$callerSym][] = $edge;
            }
        }
    }

    /**
     * @param ClassMeta-like array $meta
     * @return list<string>
     */
    private function frontierClassesOf(array $meta): array
    {
        $out = [];
        if ($meta['parent'] !== null) {
            $out[] = $meta['parent'];
        }
        foreach ($meta['interfaces'] as $i) {
            $out[] = $i;
        }
        foreach ($meta['traits'] as $t) {
            $out[] = $t;
        }
        foreach ($meta['props'] as $type) {
            $out[] = $type;
        }
        foreach ($meta['methods'] as $m) {
            foreach ($m['paramTypes'] as $t) {
                $out[] = $t;
            }
            if ($m['returnType'] !== null) {
                $out[] = $m['returnType'];
            }
        }
        return $out;
    }

    /**
     * Quick AST walk to collect every class FQN referenced inside a
     * method body. The full type-resolved edge emission lives in
     * {@see MethodBodyAnalyzer}; here we only need *names* so the BFS
     * frontier visits the files they live in.
     *
     * @return list<string>
     */
    private function bodyReferencedClasses(\PhpParser\Node\Stmt\ClassMethod $method): array
    {
        $finder = new \PhpParser\NodeFinder();
        $out = [];
        foreach ($finder->find($method->getStmts() ?? [], static fn (\PhpParser\Node $n) => $n instanceof \PhpParser\Node\Name) as $name) {
            $fqn = ltrim($name->toString(), '\\');
            if ($fqn !== '') {
                $out[$fqn] = true;
            }
        }
        return array_keys($out);
    }

    private function resolveFile(string $fqn): ?string
    {
        if ($fqn === '' || self::isReservedName($fqn)) {
            return null;
        }
        $found = $this->classLoader->findFile($fqn);
        if (!is_string($found) || $found === '') {
            return null;
        }
        $real = realpath($found);
        if ($real === false || !$this->insideProject($real)) {
            return null;
        }
        return $real;
    }

    private function insideProject(string $absPath): bool
    {
        $rootPrefix = $this->rootReal . DIRECTORY_SEPARATOR;
        if (!str_starts_with($absPath, $rootPrefix) && $absPath !== $this->rootReal) {
            return false;
        }
        if ($this->vendorReal !== false) {
            $vendorPrefix = $this->vendorReal . DIRECTORY_SEPARATOR;
            if (str_starts_with($absPath, $vendorPrefix) || $absPath === $this->vendorReal) {
                return false;
            }
        }
        return true;
    }

    private static function isReservedName(string $fqn): bool
    {
        return in_array(
            strtolower($fqn),
            ['self', 'static', 'parent', 'true', 'false', 'null',
             'mixed', 'void', 'never', 'iterable', 'callable',
             'object', 'array', 'int', 'string', 'float', 'bool',
             '\\closure', 'closure'],
            true,
        );
    }
}
