<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Fast AST-only class index for discovery collectors.
 *
 * Replaces the BetterReflection-based scan that the per-class
 * `isSubclassOf` / `implementsInterface` consumers in {@see PhpUnitCollector}
 * and {@see JobCollector} were paying through the nose for: on a
 * Laravel-sized `app/` tree those calls walk the inheritance chain via
 * BetterReflection's composer-aware locator, parsing vendor files for
 * each parent lookup and burning minutes per directory.
 *
 * This index does one nikic/php-parser pass per file, records each
 * class's direct ancestors as resolved FQN strings (NameResolver), and
 * answers `isSubclassOf()` via a hash-map BFS. Ancestors that aren't in
 * the index (typical vendor base classes) still match on direct
 * extends/implements — that's the case test classes and queued jobs
 * actually need.
 */
final class ClassIndex
{
    /** @var array<string, ClassEntry> */
    private array $byFqn = [];

    private function __construct() {}

    /** @param list<string> $dirs */
    public static function buildFromDirs(array $dirs): self
    {
        $index  = new self();
        $parser = (new ParserFactory())->createForHostVersion();
        foreach (self::iterPhpFiles($dirs) as $file) {
            $code = @file_get_contents($file);
            if (!is_string($code)) {
                continue;
            }
            try {
                $ast = $parser->parse($code);
            } catch (\Throwable) {
                continue;
            }
            if ($ast === null) {
                continue;
            }
            $visitor = new ClassIndexVisitor($file);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            foreach ($visitor->entries as $entry) {
                // First wins — multiple files claiming the same FQN is a
                // project bug; respecting load order would need composer
                // semantics we don't have here.
                $index->byFqn[$entry->fqn] ??= $entry;
            }
        }
        return $index;
    }

    /** @return iterable<ClassEntry> */
    public function all(): iterable
    {
        foreach ($this->byFqn as $entry) {
            yield $entry;
        }
    }

    public function get(string $fqn): ?ClassEntry
    {
        return $this->byFqn[ltrim($fqn, '\\')] ?? null;
    }

    /**
     * `true` when `$childFqn` extends, implements, or `use`-s
     * `$ancestorFqn` somewhere up the chain. Ancestors that aren't in
     * the project's own scanned set match on the *first* hop only — we
     * stop chasing inheritance the moment we leave indexed territory.
     */
    public function isSubclassOf(string $childFqn, string $ancestorFqn): bool
    {
        $child    = ltrim($childFqn, '\\');
        $ancestor = ltrim($ancestorFqn, '\\');
        if ($child === $ancestor) {
            return true;
        }
        $queue = [$child];
        $seen  = [$child => true];
        while ($queue !== []) {
            $fqn = array_shift($queue);
            $entry = $this->byFqn[$fqn] ?? null;
            if ($entry === null) {
                // Out-of-index hop — direct ancestors checked at the
                // parent that pushed us here.
                continue;
            }
            foreach (self::directAncestors($entry) as $parent) {
                if ($parent === $ancestor) {
                    return true;
                }
                if (isset($seen[$parent])) {
                    continue;
                }
                $seen[$parent] = true;
                $queue[] = $parent;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function directAncestors(ClassEntry $entry): array
    {
        $out = [];
        if ($entry->extends !== null) {
            $out[] = $entry->extends;
        }
        foreach ($entry->implements as $i) {
            $out[] = $i;
        }
        foreach ($entry->traits as $t) {
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @param list<string> $dirs
     * @return iterable<string>
     */
    private static function iterPhpFiles(array $dirs): iterable
    {
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    yield $file->getPathname();
                }
            }
        }
    }
}

/**
 * @internal
 */
final class ClassEntry
{
    /**
     * @param list<string> $implements resolved FQNs (NameResolver)
     * @param list<string> $traits     resolved FQNs
     * @param array<string, MethodEntry> $methods method-name → method
     * @param list<string> $attributeNames attribute FQNs on the class
     */
    public function __construct(
        public readonly string $fqn,
        public readonly string $file,
        public readonly int $startLine,
        public readonly bool $isAbstract,
        public readonly bool $isInterface,
        public readonly bool $isTrait,
        public readonly ?string $extends,
        public readonly array $implements,
        public readonly array $traits,
        public readonly array $methods,
        public readonly array $attributeNames,
    ) {}
}

/**
 * @internal
 */
final class MethodEntry
{
    /** @param list<string> $attributeNames attribute FQNs on the method */
    public function __construct(
        public readonly string $name,
        public readonly int $startLine,
        public readonly bool $isPublic,
        public readonly bool $isAbstract,
        public readonly array $attributeNames,
    ) {}
}

/**
 * @internal — collects ClassEntry rows while NameResolver is feeding
 * resolved FQNs into the AST. One visitor per file.
 */
final class ClassIndexVisitor extends NodeVisitorAbstract
{
    /** @var list<ClassEntry> */
    public array $entries = [];

    public function __construct(private readonly string $file) {}

    public function enterNode(Node $node): null
    {
        if (!($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_)) {
            return null;
        }
        $fqn = $node->namespacedName?->toString();
        if ($fqn === null || $fqn === '') {
            return null;
        }

        $extends = null;
        $implements = [];
        if ($node instanceof Class_) {
            if ($node->extends !== null) {
                $extends = $node->extends->toString();
            }
            foreach ($node->implements as $impl) {
                $implements[] = $impl->toString();
            }
        } elseif ($node instanceof Interface_) {
            foreach ($node->extends as $impl) {
                $implements[] = $impl->toString();
            }
        }

        $traits = [];
        foreach ($node->stmts ?? [] as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $t) {
                    $traits[] = $t->toString();
                }
            }
        }

        $methods = [];
        foreach ($node->getMethods() as $method) {
            $methods[$method->name->toString()] = new MethodEntry(
                name: $method->name->toString(),
                startLine: $method->getStartLine() ?: 0,
                isPublic: $method->isPublic(),
                isAbstract: $method->isAbstract(),
                attributeNames: self::attributeNames($method->attrGroups),
            );
        }

        $this->entries[] = new ClassEntry(
            fqn: $fqn,
            file: $this->file,
            startLine: $node->getStartLine() ?: 0,
            isAbstract: $node instanceof Class_ && $node->isAbstract(),
            isInterface: $node instanceof Interface_,
            isTrait: $node instanceof Trait_,
            extends: $extends,
            implements: $implements,
            traits: $traits,
            methods: $methods,
            attributeNames: self::attributeNames($node->attrGroups ?? []),
        );
        return null;
    }

    /**
     * @param list<AttributeGroup> $groups
     * @return list<string>
     */
    private static function attributeNames(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                $out[] = $attr->name->toString();
            }
        }
        return $out;
    }
}
