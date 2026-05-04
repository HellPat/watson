<?php

declare(strict_types=1);

namespace Watson\Core\Reach;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Transitive (call-graph) reach pass. Where {@see FileLevelReach} only
 * checks whether the entry point's own handler file is in the diff, this
 * pass walks every class referenced from the handler — `use` imports,
 * `new X()`, `X::method()`, `X::class`, `extends`, `implements`, type
 * hints — and intersects the resulting closure of project files with the
 * diff. Any reached file in the diff marks the entry point as affected.
 *
 * Vendor and out-of-project files are pruned at the BFS frontier, so a
 * service edit (e.g. `App\Plu\Services\ProductService::userVisible…`)
 * still surfaces every queued job, route handler, or command that calls
 * into that service.
 *
 * The pass uses BetterReflection (via {@see StaticReflector}) to resolve
 * fully-qualified class names without ever loading user code, and
 * `nikic/php-parser`'s `NameResolver` to resolve unqualified names
 * against `use` statements + the surrounding namespace.
 *
 * Tradeoffs vs file-level:
 * - **Recall** is much higher: any class transitively depended on counts.
 * - **Precision** drops some — string-encoded classnames (`'App\Foo'`),
 *   container lookups (`app(Foo::class)` *is* caught — that's
 *   `Foo::class`), and deeply dynamic dispatch are best-effort.
 * - **Cost** is one parse per visited project file, capped by the
 *   `$maxVisited` ceiling.
 */
final class TransitiveReach
{
    private const MAX_VISITED_DEFAULT = 5000;

    /**
     * @param list<EntryPoint> $entryPoints
     * @param list<string>     $changedFiles absolute paths
     * @return list<int>       indices into $entryPoints whose closure intersects the diff
     */
    public static function affectedIndices(
        array $entryPoints,
        array $changedFiles,
        StaticReflector $reflector,
        string $projectRoot,
        int $maxVisited = self::MAX_VISITED_DEFAULT,
    ): array {
        $changedSet = self::buildChangedSet($changedFiles);
        if ($changedSet === []) {
            return [];
        }

        $rootReal = realpath($projectRoot);
        if ($rootReal === false) {
            return [];
        }
        $vendorReal = realpath($projectRoot . '/vendor');

        $parser = (new ParserFactory())->createForHostVersion();
        $finder = new NodeFinder();
        // Cache: absolute file path → list of project-internal absolute file
        // paths it directly references. Built lazily during the first BFS
        // and reused across every subsequent entry point's traversal.
        $fileEdges = [];

        $hits = [];
        foreach ($entryPoints as $idx => $ep) {
            $reached = self::collectClosure(
                $ep->handlerPath,
                $reflector,
                $parser,
                $finder,
                $rootReal,
                $vendorReal,
                $maxVisited,
                $fileEdges,
            );
            foreach ($reached as $file) {
                if (isset($changedSet[$file])) {
                    $hits[] = $idx;
                    break;
                }
            }
        }

        return $hits;
    }

    /** @param list<string> $changedFiles */
    private static function buildChangedSet(array $changedFiles): array
    {
        $set = [];
        foreach ($changedFiles as $f) {
            if ($f === '') {
                continue;
            }
            $real = realpath($f);
            if ($real !== false) {
                $set[$real] = true;
            }
            $set[$f] = true;
        }
        return $set;
    }

    /**
     * BFS the static call graph from $startFile, returning the set of
     * project files reached. The first time a file is visited we resolve
     * its outgoing edges (referenced project files) and cache them in
     * `$fileEdges`; subsequent traversals reuse the edge list rather than
     * reparsing the file.
     *
     * @param array<string, list<string>> $fileEdges in-out cache: file → outgoing edges
     */
    private static function collectClosure(
        string $startFile,
        StaticReflector $reflector,
        \PhpParser\Parser $parser,
        NodeFinder $finder,
        string $rootReal,
        string|false $vendorReal,
        int $maxVisited,
        array &$fileEdges,
    ): array {
        $startReal = realpath($startFile);
        if ($startReal === false) {
            return [];
        }
        if (!self::insideProject($startReal, $rootReal, $vendorReal)) {
            return [];
        }

        $visited = [$startReal => true];
        $queue   = [$startReal];

        while ($queue !== []) {
            if (count($visited) >= $maxVisited) {
                break;
            }
            $file = array_shift($queue);
            $edges = $fileEdges[$file] ?? null;
            if ($edges === null) {
                $edges = self::resolveOutgoingEdges($file, $reflector, $parser, $finder, $rootReal, $vendorReal);
                $fileEdges[$file] = $edges;
            }
            foreach ($edges as $next) {
                if (isset($visited[$next])) {
                    continue;
                }
                $visited[$next] = true;
                $queue[] = $next;
            }
        }

        return array_keys($visited);
    }

    /**
     * Parse $file and return the absolute paths of every project-internal
     * file it references via class names. Vendor + out-of-project files
     * are pruned here so the cached edge list stays project-scoped.
     *
     * @return list<string>
     */
    private static function resolveOutgoingEdges(
        string $file,
        StaticReflector $reflector,
        \PhpParser\Parser $parser,
        NodeFinder $finder,
        string $rootReal,
        string|false $vendorReal,
    ): array {
        $code = @file_get_contents($file);
        if (!is_string($code)) {
            return [];
        }
        try {
            $ast = $parser->parse($code);
        } catch (\Throwable) {
            return [];
        }
        if ($ast === null) {
            return [];
        }

        $resolved = self::resolveNames($ast);
        $names    = $finder->findInstanceOf($resolved, Name::class);

        $edges = [];
        $seenFqns = [];
        foreach ($names as $name) {
            /** @var Name $name */
            $fqn = ltrim($name->toString(), '\\');
            if ($fqn === '' || isset($seenFqns[$fqn]) || self::isReservedName($fqn)) {
                continue;
            }
            $seenFqns[$fqn] = true;
            $class = $reflector->reflectClass($fqn);
            if ($class === null) {
                continue;
            }
            $classFileName = $class->getFileName();
            if ($classFileName === null || $classFileName === '') {
                continue;
            }
            $classReal = realpath($classFileName);
            if ($classReal === false || !self::insideProject($classReal, $rootReal, $vendorReal)) {
                continue;
            }
            $edges[$classReal] = true;
        }
        return array_keys($edges);
    }

    private static function isReservedName(string $fqn): bool
    {
        return in_array(
            $fqn,
            ['self', 'static', 'parent', 'true', 'false', 'null',
             'mixed', 'void', 'never', 'iterable', 'callable',
             'object', 'array', 'int', 'string', 'float', 'bool'],
            true,
        );
    }

    /**
     * Resolve unqualified class names against `use` statements + the
     * enclosing namespace so subsequent `Name` nodes carry an absolute
     * FQN we can reflect on.
     *
     * @param list<Node> $ast
     * @return list<Node>
     */
    private static function resolveNames(array $ast): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(
            null,
            ['preserveOriginalNames' => false, 'replaceNodes' => true],
        ));
        return $traverser->traverse($ast);
    }

    private static function insideProject(string $absPath, string $rootReal, string|false $vendorReal): bool
    {
        $rootPrefix = $rootReal . DIRECTORY_SEPARATOR;
        if (!str_starts_with($absPath, $rootPrefix) && $absPath !== $rootReal) {
            return false;
        }
        if ($vendorReal !== false) {
            $vendorPrefix = $vendorReal . DIRECTORY_SEPARATOR;
            if (str_starts_with($absPath, $vendorPrefix) || $absPath === $vendorReal) {
                return false;
            }
        }
        return true;
    }
}
