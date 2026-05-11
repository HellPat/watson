<?php

declare(strict_types=1);

namespace Watson\Core\Reach\CallGraph;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;

/**
 * Pure function: parse a PHP file once, return a list of `ClassMeta`
 * records — one per class / interface / trait / enum.
 *
 * Native PHP types take precedence; when a parameter, property, or
 * return type is undeclared (or written only in a docblock), we fall
 * back to {@see DocblockTypeReader}.
 *
 * The class/interface/trait/enum node hunt uses
 * {@see \PhpParser\NodeFinder} so we don't carry our own visitor for
 * what is essentially a one-line query.
 *
 * @phpstan-import-type ClassMeta from SymbolGraph
 */
final class ClassIndexer
{
    public function __construct(
        private readonly Parser $parser,
        private readonly DocblockTypeReader $docblocks,
    ) {
    }

    /**
     * Parse $absFile once, return a list of (className, ClassMeta) +
     * the matching {@see ClassMethod} AST nodes — and forget the rest
     * of the AST so the caller can either retain or discard the method
     * nodes without holding the whole parse tree in memory.
     *
     * Two callers exist:
     *   - {@see CallGraphBuilder::discoverClasses()} keeps only `meta`
     *     in Phase 1 (low memory footprint).
     *   - {@see CallGraphBuilder::emitEdges()} re-parses the same file
     *     in Phase 2 and consumes `methodNodes` for the body walk.
     *
     * @return list<array{name: string, meta: ClassMeta, methodNodes: array<string, ClassMethod>}>
     */
    public function indexFile(string $absFile): array
    {
        $code = @file_get_contents($absFile);
        if (!is_string($code)) {
            return [];
        }
        try {
            $ast = $this->parser->parse($code);
        } catch (\Throwable) {
            return [];
        }
        if ($ast === null) {
            return [];
        }

        // First pass: collect use-statement aliases for docblock resolution.
        // We do this BEFORE NameResolver mutates the tree because that
        // visitor inlines the `use` targets and we'd lose the alias map.
        $useMap = $this->collectUseMap($ast);

        // Second pass: NameResolver so native type hints, extends,
        // implements, etc. carry fully-qualified Name nodes.
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => false]));
        $ast = $traverser->traverse($ast);

        $finder = new NodeFinder();
        /** @var list<Class_|Interface_|Trait_|Enum_> $declarations */
        $declarations = $finder->find(
            $ast,
            static fn (Node $n) => $n instanceof Class_ || $n instanceof Interface_ || $n instanceof Trait_ || $n instanceof Enum_,
        );

        $out = [];
        foreach ($declarations as $node) {
            $name = $node->namespacedName?->toString() ?? $node->name?->toString();
            if ($name === null) {
                continue;
            }
            $namespace = $this->namespaceOf($name);
            $methodNodes = [];
            foreach ($node->stmts ?? [] as $stmt) {
                if ($stmt instanceof ClassMethod) {
                    $methodNodes[$stmt->name->toString()] = $stmt;
                }
            }
            $out[] = [
                'name' => $name,
                'meta' => $this->buildMeta($node, $absFile, $namespace, $useMap),
                'methodNodes' => $methodNodes,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, string> $useMap
     * @return ClassMeta
     */
    private function buildMeta(Class_|Interface_|Trait_|Enum_ $node, string $file, string $namespace, array $useMap): array
    {
        return [
            'file'       => $file,
            'parent'     => $this->parentOf($node),
            'interfaces' => $this->interfacesOf($node),
            'traits'     => $this->traitsOf($node),
            'methods'    => $this->methodsOf($node, $namespace, $useMap),
            'props'      => $this->propertiesOf($node, $namespace, $useMap),
        ];
    }

    private function parentOf(Class_|Interface_|Trait_|Enum_ $node): ?string
    {
        if ($node instanceof Class_ && $node->extends !== null) {
            return $node->extends->toString();
        }
        return null;
    }

    /** @return list<string> */
    private function interfacesOf(Class_|Interface_|Trait_|Enum_ $node): array
    {
        $names = [];
        if ($node instanceof Class_) {
            foreach ($node->implements as $i) {
                $names[] = $i->toString();
            }
        } elseif ($node instanceof Interface_) {
            foreach ($node->extends as $i) {
                $names[] = $i->toString();
            }
        } elseif ($node instanceof Enum_) {
            foreach ($node->implements as $i) {
                $names[] = $i->toString();
            }
        }
        return $names;
    }

    /** @return list<string> */
    private function traitsOf(Class_|Interface_|Trait_|Enum_ $node): array
    {
        $traits = [];
        foreach ($node->stmts ?? [] as $stmt) {
            if (!$stmt instanceof TraitUse) {
                continue;
            }
            foreach ($stmt->traits as $t) {
                $traits[] = $t->toString();
            }
        }
        return $traits;
    }

    /**
     * @param array<string, string> $useMap
     * @return array<string, array{paramTypes: array<string,string>, returnType: ?string, line: int, endLine: int}>
     */
    private function methodsOf(Class_|Interface_|Trait_|Enum_ $node, string $namespace, array $useMap): array
    {
        $methods = [];
        foreach ($node->stmts ?? [] as $stmt) {
            if (!$stmt instanceof ClassMethod) {
                continue;
            }
            $methods[$stmt->name->toString()] = [
                'paramTypes' => $this->paramTypesOf($stmt, $namespace, $useMap),
                'returnType' => $this->resolveReturnType($stmt, $namespace, $useMap),
                'line'       => $stmt->getStartLine(),
                'endLine'    => $stmt->getEndLine(),
            ];
        }
        return $methods;
    }

    /**
     * @param array<string, string> $useMap
     * @return array<string, string>
     */
    private function paramTypesOf(ClassMethod $method, string $namespace, array $useMap): array
    {
        $doc = $method->getDocComment()?->getText();
        $out = [];
        foreach ($method->params as $param) {
            $name = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                ? $param->var->name
                : null;
            if ($name === null) {
                continue;
            }
            $type = $this->typeFqnFromNode($param->type) ?? $this->docblocks->forParam($doc, $name, $namespace, $useMap);
            if ($type !== null) {
                $out[$name] = $type;
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $useMap
     */
    private function resolveReturnType(ClassMethod $method, string $namespace, array $useMap): ?string
    {
        return $this->typeFqnFromNode($method->returnType)
            ?? $this->docblocks->forReturn($method->getDocComment()?->getText(), $namespace, $useMap);
    }

    /**
     * Properties are typed via PHP-native declarations *or* a `@var` tag.
     * Constructor-promoted properties are picked up via the `__construct`
     * method's param types in {@see methodsOf()} — we re-emit them here
     * so `$this->prop` lookups resolve without round-tripping through
     * the call graph.
     *
     * @param array<string, string> $useMap
     * @return array<string, string>
     */
    private function propertiesOf(Class_|Interface_|Trait_|Enum_ $node, string $namespace, array $useMap): array
    {
        $props = [];
        foreach ($node->stmts ?? [] as $stmt) {
            if ($stmt instanceof Property) {
                $type = $this->typeFqnFromNode($stmt->type);
                $docType = $type === null
                    ? $this->docblocks->forProperty($stmt->getDocComment()?->getText(), $namespace, $useMap)
                    : null;
                $resolved = $type ?? $docType;
                if ($resolved === null) {
                    continue;
                }
                foreach ($stmt->props as $pp) {
                    $props[$pp->name->toString()] = $resolved;
                }
                continue;
            }
            // Promoted properties — read off the constructor's typed params.
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === '__construct') {
                foreach ($stmt->params as $param) {
                    if ($param->flags === 0) {
                        continue;
                    }
                    $name = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                        ? $param->var->name
                        : null;
                    if ($name === null) {
                        continue;
                    }
                    $type = $this->typeFqnFromNode($param->type)
                        ?? $this->docblocks->forParam($stmt->getDocComment()?->getText(), $name, $namespace, $useMap);
                    if ($type !== null) {
                        $props[$name] = $type;
                    }
                }
            }
        }
        return $props;
    }

    private function typeFqnFromNode(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            return $this->typeFqnFromNode($type->type);
        }
        if ($type instanceof Identifier) {
            return null; // builtin scalar (int / string / mixed / …)
        }
        if ($type instanceof Name) {
            $fqn = ltrim($type->toString(), '\\');
            return $fqn === '' ? null : $fqn;
        }
        return null; // union / intersection → bail
    }

    /**
     * Walk the AST for `use ...;` / `use ...\{...};` blocks and build
     * the alias → FQN map. Aliases default to the trailing segment of
     * the imported name when no `as` clause is present.
     *
     * @param list<Node> $ast
     * @return array<string, string>
     */
    private function collectUseMap(array $ast): array
    {
        $finder = new NodeFinder();
        /** @var list<Use_|GroupUse> $useStmts */
        $useStmts = $finder->find(
            $ast,
            static fn (Node $n) => $n instanceof Use_ || $n instanceof GroupUse,
        );

        $map = [];
        foreach ($useStmts as $stmt) {
            if ($stmt instanceof Use_) {
                foreach ($stmt->uses as $u) {
                    $alias = $u->alias?->toString() ?? $u->name->getLast();
                    $map[$alias] = $u->name->toString();
                }
                continue;
            }
            // GroupUse: `use App\Service\{Foo, Bar as Baz};`
            $prefix = $stmt->prefix->toString();
            foreach ($stmt->uses as $u) {
                $alias = $u->alias?->toString() ?? $u->name->getLast();
                $map[$alias] = $prefix . '\\' . $u->name->toString();
            }
        }
        return $map;
    }

    private function namespaceOf(string $classFqn): string
    {
        $pos = strrpos($classFqn, '\\');
        return $pos === false ? '' : substr($classFqn, 0, $pos);
    }
}
