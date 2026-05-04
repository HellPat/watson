<?php

declare(strict_types=1);

namespace Watson\Core\Reach;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Composer\Autoload\ClassLoader;
use Watson\Core\Entrypoint\EntryPoint;

/**
 * Transitive (call-graph) reach pass. Where {@see FileLevelReach} only
 * checks whether the entry point's own handler file is in the diff, this
 * pass walks every class referenced from the handler — `use` imports,
 * `new X()`, `X::method()`, `X::class`, `extends`, `implements`, type
 * hints — and marks the entry point as affected when its closure of
 * project files intersects the diff.
 *
 * Algorithm:
 * 1. Forward graph — BFS from every entry-point handler file, parsing
 *    each visited project file once. Edges are project-internal class
 *    references (vendor / out-of-project files are pruned at the
 *    frontier). Each unique file is parsed at most once per run.
 * 2. Reverse BFS from the changed files — gives the set of project
 *    files that *transitively reach* something in the diff.
 * 3. For each entry point, hit when its handler file is in that set.
 *
 * Doing the two BFSes globally (instead of per entry point) keeps the
 * cost ~O(reachable files) total rather than O(reachable files × entry
 * points) — the practical difference between sub-second and "watson
 * times out" on a Laravel app with hundreds of routes.
 *
 * Recall is high; precision is best-effort: string-encoded class names
 * (`'App\Foo'` as a literal) and dynamic dispatch are not chased,
 * `Foo::class` is.
 */
final class TransitiveReach
{
    private const MAX_FILES_DEFAULT = 5000;
    private const MAX_DEPTH_DEFAULT = 3;

    /**
     * @param list<EntryPoint> $entryPoints
     * @param list<string>     $changedFiles absolute paths
     * @param int              $maxDepth     hops from any entry-point handler file we follow before stopping; bounded recall in exchange for a much sharper signal on real apps
     * @return list<int>       indices into $entryPoints whose closure intersects the diff
     */
    public static function affectedIndices(
        array $entryPoints,
        array $changedFiles,
        ClassLoader $classLoader,
        string $projectRoot,
        int $maxFiles = self::MAX_FILES_DEFAULT,
        int $maxDepth = self::MAX_DEPTH_DEFAULT,
    ): array {
        if ($entryPoints === [] || $changedFiles === []) {
            return [];
        }

        $rootReal = realpath($projectRoot);
        if ($rootReal === false) {
            return [];
        }
        $vendorReal = realpath($projectRoot . '/vendor');

        $changedSet = self::buildChangedSet($changedFiles);
        if ($changedSet === []) {
            return [];
        }

        $parser = (new ParserFactory())->createForHostVersion();

        // Step 1: forward graph from every entry-point handler file.
        $seeds = self::collectSeedFiles($entryPoints, $rootReal, $vendorReal);
        $forward = self::buildForwardGraph(
            $seeds,
            $classLoader,
            $parser,
            $rootReal,
            $vendorReal,
            $maxFiles,
            $maxDepth,
        );

        // Step 2: invert and BFS backwards from the diff.
        $reverse = self::invert($forward);
        $reachable = self::reverseClosure($changedSet, $reverse);

        // Step 3: mark entry points whose handler file is reachable.
        $hits = [];
        foreach ($entryPoints as $idx => $ep) {
            $real = realpath($ep->handlerPath);
            $key = $real !== false ? $real : $ep->handlerPath;
            if (isset($reachable[$key])) {
                $hits[] = $idx;
            }
        }
        return $hits;
    }

    /**
     * @param list<EntryPoint> $entryPoints
     * @return list<string>
     */
    private static function collectSeedFiles(array $entryPoints, string $rootReal, string|false $vendorReal): array
    {
        $seeds = [];
        foreach ($entryPoints as $ep) {
            if ($ep->handlerPath === '') {
                continue;
            }
            $real = realpath($ep->handlerPath);
            if ($real === false) {
                continue;
            }
            if (!self::insideProject($real, $rootReal, $vendorReal)) {
                continue;
            }
            $seeds[$real] = true;
        }
        return array_keys($seeds);
    }

    /**
     * @param list<string> $seeds
     * @return array<string, list<string>> file → outgoing edges (project-internal absolute paths)
     */
    private static function buildForwardGraph(
        array $seeds,
        ClassLoader $classLoader,
        \PhpParser\Parser $parser,
        string $rootReal,
        string|false $vendorReal,
        int $maxFiles,
        int $maxDepth,
    ): array {
        $forward  = [];
        $fqnCache = [];
        $depth    = []; // file → BFS distance from nearest seed
        $queue    = [];
        foreach ($seeds as $seed) {
            $depth[$seed] = 0;
            $queue[]      = $seed;
        }

        while ($queue !== []) {
            if (count($forward) >= $maxFiles) {
                break;
            }
            $file = array_shift($queue);
            if (isset($forward[$file])) {
                continue;
            }
            $d = $depth[$file] ?? 0;
            if ($d >= $maxDepth) {
                // Beyond the depth cap we still record the file but with
                // an empty edge list, so the reverse closure can't walk
                // through it into unrelated subsystems.
                $forward[$file] = [];
                continue;
            }
            $edges = self::resolveOutgoingEdges($file, $classLoader, $parser, $rootReal, $vendorReal, $fqnCache);
            $forward[$file] = $edges;
            foreach ($edges as $next) {
                if (!isset($forward[$next]) && !isset($depth[$next])) {
                    $depth[$next] = $d + 1;
                    $queue[]      = $next;
                }
            }
        }
        return $forward;
    }

    /**
     * @param array<string, list<string>> $forward
     * @return array<string, list<string>>
     */
    private static function invert(array $forward): array
    {
        $reverse = [];
        foreach ($forward as $src => $targets) {
            foreach ($targets as $t) {
                $reverse[$t][] = $src;
            }
        }
        return $reverse;
    }

    /**
     * Reverse BFS from $changedSet through $reverse. Files that don't
     * appear in $reverse simply aren't reached from anything; that's
     * fine — they only matter if they ARE in $changedSet themselves.
     *
     * @param array<string, true>          $changedSet
     * @param array<string, list<string>>  $reverse
     * @return array<string, true>
     */
    private static function reverseClosure(array $changedSet, array $reverse): array
    {
        $reached = [];
        $queue   = [];
        foreach ($changedSet as $f => $_) {
            $reached[$f] = true;
            $queue[]     = $f;
        }
        while ($queue !== []) {
            $f       = array_shift($queue);
            $callers = $reverse[$f] ?? [];
            foreach ($callers as $caller) {
                if (!isset($reached[$caller])) {
                    $reached[$caller] = true;
                    $queue[]          = $caller;
                }
            }
        }
        return $reached;
    }

    /**
     * Parse $file and return the absolute paths of every project-internal
     * file it references via class names. Vendor + out-of-project files
     * are pruned here so the cached edge list stays project-scoped.
     *
     * @param array<string, ?string> $fqnCache in-out: FQN → resolved project file (or null when external/unknown)
     * @return list<string>
     */
    private static function resolveOutgoingEdges(
        string $file,
        ClassLoader $classLoader,
        \PhpParser\Parser $parser,
        string $rootReal,
        string|false $vendorReal,
        array &$fqnCache,
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
        $names    = self::collectMeaningfulNames($resolved);

        $edges    = [];
        $seenFqns = [];
        foreach ($names as $name) {
            /** @var Name $name */
            $fqn = ltrim($name->toString(), '\\');
            if ($fqn === '' || isset($seenFqns[$fqn]) || self::isReservedName($fqn)) {
                continue;
            }
            $seenFqns[$fqn] = true;

            if (array_key_exists($fqn, $fqnCache)) {
                $cached = $fqnCache[$fqn];
                if ($cached !== null) {
                    $edges[$cached] = true;
                }
                continue;
            }

            $resolvedFile = null;
            $found        = $classLoader->findFile($fqn);
            if (is_string($found) && $found !== '') {
                $real = realpath($found);
                if ($real !== false && self::insideProject($real, $rootReal, $vendorReal)) {
                    $resolvedFile = $real;
                }
            }
            $fqnCache[$fqn] = $resolvedFile;
            if ($resolvedFile !== null) {
                $edges[$resolvedFile] = true;
            }
        }
        return array_keys($edges);
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

    /**
     * Collect every `Name` node that represents a meaningful class reference
     * in the source (new, static call, type hint, extends/implements,
     * `Foo::class`, …). `use` and `group use` statement subtrees are
     * skipped — those imports are already inlined into the body's Name
     * nodes by the NameResolver pass, and treating each `use X` as its own
     * graph edge would follow every unused import and explode the closure.
     *
     * @param list<Node> $ast
     * @return list<Name>
     */
    private static function collectMeaningfulNames(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Name> */
            public array $names = [];

            public function enterNode(Node $node): null|int
            {
                if ($node instanceof Use_ || $node instanceof GroupUse) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Name) {
                    $this->names[] = $node;
                }
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->names;
    }

    /** @return array<string, true> */
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
}
