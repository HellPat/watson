<?php

declare(strict_types=1);

namespace Watson\Core\Reach\CallGraph;

/**
 * Static representation of the symbol-level call graph built by
 * {@see MethodResolver}.
 *
 * - Node identity: `"Class\\Fqn::method"` (or `"Class\\Fqn::*"` for
 *   class-level coupling like `extends`, `implements`, `Foo::class`,
 *   type hints).
 * - Edge direction: caller → callee.
 *
 * @phpstan-type ClassMeta array{
 *     file: string,
 *     parent: ?string,
 *     interfaces: list<string>,
 *     traits: list<string>,
 *     methods: array<string, array{paramTypes: array<string,string>, returnType: ?string, line: int, endLine: int}>,
 *     props: array<string, string>,
 * }
 * @phpstan-type Edge array{target: string, kind: string}
 */
final class SymbolGraph
{
    public const KIND_RESOLVED   = 'Resolved';
    public const KIND_NAME_ONLY  = 'NameOnly';
    public const KIND_CLASS_LEVEL = 'ClassLevel';

    /**
     * @param array<string, ClassMeta> $classes
     * @param array<string, list<Edge>> $edges caller symbol → outgoing edges
     * @param array<string, list<string>> $fileSymbols absolute file path → list of `Class::method` (and `Class::*`) defined in the file
     * @param array<string, list<string>> $methodIndex method-name → list of `Class::method` (every class that defines a method with that name)
     */
    public function __construct(
        public readonly array $classes,
        public readonly array $edges,
        public readonly array $fileSymbols,
        public readonly array $methodIndex,
    ) {
    }

    /**
     * Walk the parent / interface / trait chain to find the most specific
     * class that *defines* `$method`. Returns the FQN that owns the
     * definition (so the call resolves to a real node), or null if no
     * class on the chain defines it (typically a magic call, an external
     * type, or a name we couldn't resolve).
     */
    public function ownerOfMethod(string $classFqn, string $method): ?string
    {
        $seen = [];
        $stack = [$classFqn];
        while ($stack !== []) {
            $cur = array_pop($stack);
            if (isset($seen[$cur])) {
                continue;
            }
            $seen[$cur] = true;
            $meta = $this->classes[$cur] ?? null;
            if ($meta === null) {
                continue;
            }
            if (isset($meta['methods'][$method])) {
                return $cur;
            }
            foreach ($meta['traits'] as $t) {
                $stack[] = $t;
            }
            if ($meta['parent'] !== null) {
                $stack[] = $meta['parent'];
            }
            foreach ($meta['interfaces'] as $i) {
                $stack[] = $i;
            }
        }

        return null;
    }

    /**
     * Walk the chain to find the class that declares typed property
     * `$prop`. Returns the property's declared type FQN or null.
     */
    public function propType(string $classFqn, string $prop): ?string
    {
        $seen = [];
        $stack = [$classFqn];
        while ($stack !== []) {
            $cur = array_pop($stack);
            if (isset($seen[$cur])) {
                continue;
            }
            $seen[$cur] = true;
            $meta = $this->classes[$cur] ?? null;
            if ($meta === null) {
                continue;
            }
            if (isset($meta['props'][$prop])) {
                return $meta['props'][$prop];
            }
            foreach ($meta['traits'] as $t) {
                $stack[] = $t;
            }
            if ($meta['parent'] !== null) {
                $stack[] = $meta['parent'];
            }
        }

        return null;
    }

    /**
     * Return the declared return type for `Class::method` resolved
     * through the inheritance / trait chain.
     */
    public function returnType(string $classFqn, string $method): ?string
    {
        $owner = $this->ownerOfMethod($classFqn, $method);
        if ($owner === null) {
            return null;
        }

        return $this->classes[$owner]['methods'][$method]['returnType'] ?? null;
    }

    /**
     * Return the symbol identities defined in `$absFile`. Used to map a
     * {@see \Watson\Core\Diff\ChangedSymbol} back to graph nodes.
     *
     * @return list<string>
     */
    public function symbolsInFile(string $absFile): array
    {
        return $this->fileSymbols[$absFile] ?? [];
    }
}
