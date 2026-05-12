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
 * Single-pass AST class index over a set of directories. Records each
 * declared class's direct ancestors as resolved FQN strings (via
 * NameResolver) and answers `isSubclassOf()` with a hash-map BFS.
 * Ancestors outside the indexed set (typical vendor base classes) still
 * match on the first hop.
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

    /**
     * Concrete classes that either extend / implement / use `$ancestorFqn`,
     * or carry an `$attrFqn` attribute (when given). Abstract classes,
     * interfaces, and traits are skipped — every collector wants the
     * dispatchable concrete shape.
     *
     * @return iterable<ClassEntry>
     */
    public function concreteSubclassesOf(string $ancestorFqn, ?string $attrFqn = null): iterable
    {
        foreach ($this->byFqn as $entry) {
            if ($entry->isAbstract || $entry->isInterface || $entry->isTrait) {
                continue;
            }
            if ($this->isSubclassOf($entry->fqn, $ancestorFqn)) {
                yield $entry;
                continue;
            }
            if ($attrFqn !== null && isset($entry->attributeArgs[$attrFqn])) {
                yield $entry;
            }
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
     * @param list<string> $implements                       resolved FQNs (NameResolver)
     * @param list<string> $traits                           resolved FQNs
     * @param array<string, MethodEntry> $methods            method-name → method
     * @param array<string, list<scalar|null>> $attributeArgs attribute FQN → positional + named arg scalars (named args come before positional in insertion order); presence of the key means the attribute is present, even when args is empty
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
        public readonly array $attributeArgs,
    ) {}

    public function shortName(): string
    {
        $pos = strrpos($this->fqn, '\\');
        return $pos === false ? $this->fqn : substr($this->fqn, $pos + 1);
    }
}

/**
 * @internal
 */
final class MethodEntry
{
    /** @param array<string, list<scalar|null>> $attributeArgs attribute FQN → arg scalars (same shape as ClassEntry::$attributeArgs) */
    public function __construct(
        public readonly string $name,
        public readonly int $startLine,
        public readonly bool $isPublic,
        public readonly bool $isAbstract,
        public readonly array $attributeArgs,
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
                attributeArgs: self::attributeArgs($method->attrGroups),
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
            attributeArgs: self::attributeArgs($node->attrGroups ?? []),
        );
        return null;
    }

    /**
     * Capture each attribute's scalar argument values keyed by the
     * attribute's resolved FQN. Non-scalar arguments (arrays, enums,
     * class consts, …) are dropped — the discovery layer only needs
     * the string-valued ones (e.g. `#[AsCommand('name')]`).
     *
     * @param list<AttributeGroup> $groups
     * @return array<string, list<scalar|null>>
     */
    private static function attributeArgs(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                $name = $attr->name->toString();
                $args = $out[$name] ?? [];
                foreach ($attr->args as $arg) {
                    $value = $arg->value;
                    if ($value instanceof Node\Scalar\String_) {
                        $args[] = $value->value;
                    } elseif ($value instanceof Node\Scalar\Int_) {
                        $args[] = $value->value;
                    } elseif ($value instanceof Node\Scalar\Float_) {
                        $args[] = $value->value;
                    } elseif ($value instanceof Node\Expr\ConstFetch) {
                        $bool = strtolower($value->name->toString());
                        $args[] = $bool === 'true' ? true : ($bool === 'false' ? false : null);
                    }
                }
                $out[$name] = $args;
            }
        }
        return $out;
    }
}
