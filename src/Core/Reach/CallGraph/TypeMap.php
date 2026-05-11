<?php

declare(strict_types=1);

namespace Watson\Core\Reach\CallGraph;

/**
 * Per-method scope of local-variable → class-FQN bindings.
 *
 * Walks alongside {@see MethodBodyAnalyzer} and answers the question
 * *"what is the type of this variable expression?"* — without doing any
 * flow-sensitive analysis (no branch joins, no narrowing).
 *
 * Seeded once at method entry with `$this` + typed parameters, then
 * updated linearly by `Assign` statements as the analyzer walks the
 * body top-to-bottom.
 *
 * Class-level metadata (typed properties, declared return types) is
 * looked up against the {@see ClassMeta} index built upstream by
 * {@see ClassIndexer}, walking the parent / trait chain when needed.
 */
final class TypeMap
{
    /** @var array<string, string> varName → FQN */
    private array $vars = [];

    /**
     * @param array<string, array{
     *     file: string,
     *     parent: ?string,
     *     interfaces: list<string>,
     *     traits: list<string>,
     *     methods: array<string, array{paramTypes: array<string,string>, returnType: ?string, line: int, endLine: int}>,
     *     props: array<string, string>,
     * }> $classes
     */
    public function __construct(
        private readonly array $classes,
        private readonly string $currentClass,
    ) {
        $this->vars['this'] = $currentClass;
    }

    /** @param array<string, string> $paramTypes name → FQN */
    public function seedParams(array $paramTypes): void
    {
        foreach ($paramTypes as $name => $type) {
            $this->vars[$name] = $type;
        }
    }

    public function record(string $varName, string $type): void
    {
        $this->vars[$varName] = $type;
    }

    public function lookup(string $varName): ?string
    {
        return $this->vars[$varName] ?? null;
    }

    /**
     * Resolve `$this->propName` to the declared property type, walking
     * the parent / trait chain.
     */
    public function propType(string $propName): ?string
    {
        return $this->propTypeOnClass($this->currentClass, $propName);
    }

    /**
     * Resolve a property type on a different class — used by chained
     * inference like `$this->foo->bar` once we know `$this->foo`'s type.
     */
    public function propTypeOnClass(string $classFqn, string $propName): ?string
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
            if (isset($meta['props'][$propName])) {
                return $meta['props'][$propName];
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
     * Resolve `Class::method` declared return type, walking the parent /
     * trait / interface chain.
     */
    public function returnType(string $classFqn, string $methodName): ?string
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
            if (isset($meta['methods'][$methodName]['returnType']) && $meta['methods'][$methodName]['returnType'] !== null) {
                return $meta['methods'][$methodName]['returnType'];
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
}
