<?php

declare(strict_types=1);

namespace Watson\Core\Reach\CallGraph;

use Composer\Autoload\ClassLoader;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Build a symbol-level (`Class::method`) call graph by BFS-walking
 * project files starting from a seed set (typically the entry-point
 * handler classes). Each visited file is parsed once.
 *
 * Type inference is intentionally pragmatic — it resolves the cases that
 * matter for blast-radius reach in real Laravel / Symfony apps without
 * importing a full PHPStan-grade resolver:
 *
 *   - typed parameters: `function (Foo $x)` → `$x: Foo`
 *   - typed properties: `public Foo $bar` → `$this->bar: Foo`
 *   - constructor-promoted properties (params with visibility)
 *   - `$x = new Foo(…)` → `$x: Foo`
 *   - `$x = Foo::factory()` → return type of `Foo::factory`
 *   - `$x = $svc->method()` → return type when receiver resolves
 *   - `$this` → current class
 *
 * Unresolved receivers degrade to `NameOnly` edges — the method name is
 * recorded but no specific class is associated. The reverse-reach pass
 * matches those by *name* against changed symbols, with a hard fan-out
 * cap.
 */
final class MethodResolver
{
    /** @var ?Parser */
    private ?Parser $parser = null;

    /** @var array<string, true> */
    private array $visitedFiles = [];

    /** @var array<string, SymbolGraph::KIND_*[]>|array */
    private array $classes = [];

    /** @var array<string, list<array{target:string,kind:string}>> */
    private array $edges = [];

    /** @var array<string, list<string>> */
    private array $fileSymbols = [];

    /** @var array<string, list<string>> */
    private array $methodIndex = [];

    private function __construct(
        private readonly ClassLoader $classLoader,
        private readonly string $rootReal,
        private readonly string|false $vendorReal,
        private readonly int $maxFiles,
    ) {
    }

    /**
     * @param list<string>     $seedFiles  absolute project file paths
     * @param ClassLoader      $classLoader
     */
    public static function build(
        array $seedFiles,
        ClassLoader $classLoader,
        string $projectRoot,
        int $maxFiles = 5000,
    ): SymbolGraph {
        $rootReal = realpath($projectRoot);
        if ($rootReal === false) {
            return new SymbolGraph([], [], [], []);
        }
        $vendorReal = realpath($projectRoot . '/vendor');

        $self = new self($classLoader, $rootReal, $vendorReal, $maxFiles);
        $self->parser = (new ParserFactory())->createForHostVersion();

        $queue = [];
        foreach ($seedFiles as $f) {
            $real = realpath($f);
            if ($real !== false && $self->insideProject($real)) {
                $queue[] = $real;
            }
        }

        while ($queue !== [] && count($self->visitedFiles) < $maxFiles) {
            $file = array_shift($queue);
            if (isset($self->visitedFiles[$file])) {
                continue;
            }
            $self->visitedFiles[$file] = true;
            foreach ($self->processFile($file) as $followFile) {
                if (!isset($self->visitedFiles[$followFile])) {
                    $queue[] = $followFile;
                }
            }
        }

        return new SymbolGraph(
            $self->classes,
            $self->edges,
            $self->fileSymbols,
            $self->methodIndex,
        );
    }

    /**
     * Parse $file, index every class/trait/interface/enum, walk every
     * method body to emit edges, and return the set of project-internal
     * files the method bodies reference (so BFS can follow).
     *
     * @return list<string>
     */
    private function processFile(string $file): array
    {
        $code = @file_get_contents($file);
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

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => false]));
        $ast = $traverser->traverse($ast);

        $followFiles = [];
        $classesInFile = [];

        $this->walk($ast, function (Node $node) use ($file, &$followFiles, &$classesInFile): void {
            if (!($node instanceof Class_ || $node instanceof Trait_ || $node instanceof Interface_ || $node instanceof Enum_)) {
                return;
            }
            $name = $node->namespacedName?->toString() ?? $node->name?->toString();
            if ($name === null) {
                return;
            }
            $meta = $this->indexClass($node, $file);
            $this->classes[$name] = $meta;
            $classesInFile[] = $name;
            $this->fileSymbols[$file][] = $name . '::*';
            foreach (array_keys($meta['methods']) as $m) {
                $this->fileSymbols[$file][] = $name . '::' . $m;
                $this->methodIndex[$m][] = $name . '::' . $m;
            }

            $classFqnFollow = [];
            if ($node instanceof Class_) {
                if ($node->extends !== null) {
                    $classFqnFollow[] = $node->extends->toString();
                }
                foreach ($node->implements ?? [] as $i) {
                    $classFqnFollow[] = $i->toString();
                }
            } elseif ($node instanceof Interface_) {
                foreach ($node->extends ?? [] as $i) {
                    $classFqnFollow[] = $i->toString();
                }
            } elseif ($node instanceof Enum_) {
                foreach ($node->implements ?? [] as $i) {
                    $classFqnFollow[] = $i->toString();
                }
            }
            foreach ($meta['traits'] as $t) {
                $classFqnFollow[] = $t;
            }
            foreach ($classFqnFollow as $fqn) {
                $resolved = $this->resolveFile($fqn);
                if ($resolved !== null) {
                    $followFiles[$resolved] = true;
                }
            }

            // Walk every method body to emit per-method edges.
            foreach ($node->getMethods() as $method) {
                $callerSym = $name . '::' . $method->name->toString();
                $this->walkMethodBody($name, $method, $callerSym, $followFiles);
            }
        });

        return array_keys($followFiles);
    }

    /**
     * Build a {@see SymbolGraph::ClassMeta} entry. Method signatures are
     * captured but bodies are NOT yet walked — that happens in a second
     * pass once every class in the closure is indexed (so receiver-type
     * lookups succeed even on forward references inside the same file).
     *
     * @param Class_|Trait_|Interface_|Enum_ $node
     * @return array{file:string,parent:?string,interfaces:list<string>,traits:list<string>,methods:array<string,array{paramTypes:array<string,string>,returnType:?string,line:int,endLine:int}>,props:array<string,string>}
     */
    private function indexClass(Node $node, string $file): array
    {
        $parent = null;
        $interfaces = [];
        if ($node instanceof Class_) {
            if ($node->extends !== null) {
                $parent = $node->extends->toString();
            }
            foreach ($node->implements ?? [] as $i) {
                $interfaces[] = $i->toString();
            }
        } elseif ($node instanceof Interface_) {
            foreach ($node->extends ?? [] as $i) {
                $interfaces[] = $i->toString();
            }
        } elseif ($node instanceof Enum_) {
            foreach ($node->implements ?? [] as $i) {
                $interfaces[] = $i->toString();
            }
        }

        $traits = [];
        $methods = [];
        $props = [];
        foreach ($node->stmts ?? [] as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $t) {
                    $traits[] = $t->toString();
                }
                continue;
            }
            if ($stmt instanceof Property) {
                $type = $this->typeFqn($stmt->type);
                foreach ($stmt->props as $pp) {
                    if ($type !== null) {
                        $props[$pp->name->toString()] = $type;
                    }
                }
                continue;
            }
            if ($stmt instanceof ClassMethod) {
                $paramTypes = [];
                foreach ($stmt->params as $p) {
                    $t = $this->typeFqn($p->type);
                    $pname = $p->var instanceof Variable && is_string($p->var->name) ? $p->var->name : null;
                    if ($pname !== null && $t !== null) {
                        $paramTypes[$pname] = $t;
                    }
                    // Constructor-promoted property
                    if ($stmt->name->toString() === '__construct' && $p->flags !== 0 && $pname !== null && $t !== null) {
                        $props[$pname] = $t;
                    }
                }
                $methods[$stmt->name->toString()] = [
                    'paramTypes' => $paramTypes,
                    'returnType' => $this->typeFqn($stmt->returnType),
                    'line'       => $stmt->getStartLine(),
                    'endLine'    => $stmt->getEndLine(),
                ];
            }
        }

        return [
            'file'       => $file,
            'parent'     => $parent,
            'interfaces' => $interfaces,
            'traits'     => $traits,
            'methods'    => $methods,
            'props'      => $props,
        ];
    }

    /**
     * Walk one method body emitting edges. Receiver-type tracking is
     * single-pass and linear (no joins on if/else branches) — overshoots
     * are biased toward more edges (high recall).
     *
     * @param array<string, true> $followFiles
     */
    private function walkMethodBody(string $classFqn, ClassMethod $method, string $callerSym, array &$followFiles): void
    {
        // Seed var types from typed params + $this. Each typed param /
        // return type also produces a ClassLevel coupling edge — the
        // method's signature alone is enough to couple it to the type's
        // class, even when the body never dereferences the parameter.
        $varTypes = ['this' => $classFqn];
        foreach ($method->params as $p) {
            $t = $this->typeFqn($p->type);
            $pname = $p->var instanceof Variable && is_string($p->var->name) ? $p->var->name : null;
            if ($pname !== null && $t !== null) {
                $varTypes[$pname] = $t;
                $this->addEdge($callerSym, $t . '::*', SymbolGraph::KIND_CLASS_LEVEL, $followFiles);
            }
        }
        $rt = $this->typeFqn($method->returnType);
        if ($rt !== null) {
            $this->addEdge($callerSym, $rt . '::*', SymbolGraph::KIND_CLASS_LEVEL, $followFiles);
        }

        $resolver = function (Node $expr) use (&$varTypes, $classFqn): ?string {
            return $this->inferType($expr, $varTypes, $classFqn);
        };

        $this->walk(
            $method->getStmts() ?? [],
            function (Node $node) use ($callerSym, &$varTypes, $classFqn, $resolver, &$followFiles): void {
                // Track local-var types from assignments.
                if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
                    $t = $resolver($node->expr);
                    if ($t !== null) {
                        $varTypes[$node->var->name] = $t;
                    }
                }

                if ($node instanceof New_) {
                    if ($node->class instanceof Name) {
                        $targetClass = $node->class->toString();
                        $this->addEdge($callerSym, $targetClass . '::__construct', SymbolGraph::KIND_RESOLVED, $followFiles);
                    }
                    return;
                }

                if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
                    $targetClass = $node->class->toString();
                    $this->addEdge($callerSym, $targetClass . '::' . $node->name->toString(), SymbolGraph::KIND_RESOLVED, $followFiles);
                    return;
                }

                if ($node instanceof StaticPropertyFetch && $node->class instanceof Name) {
                    $this->addEdge($callerSym, $node->class->toString() . '::*', SymbolGraph::KIND_CLASS_LEVEL, $followFiles);
                    return;
                }

                if ($node instanceof ClassConstFetch && $node->class instanceof Name) {
                    $this->addEdge($callerSym, $node->class->toString() . '::*', SymbolGraph::KIND_CLASS_LEVEL, $followFiles);
                    return;
                }

                if ($node instanceof MethodCall && $node->name instanceof Identifier) {
                    $methodName = $node->name->toString();
                    $receiverType = $resolver($node->var);
                    if ($receiverType !== null) {
                        $this->addEdge($callerSym, $receiverType . '::' . $methodName, SymbolGraph::KIND_RESOLVED, $followFiles);
                    } else {
                        $this->addEdge($callerSym, '*::' . $methodName, SymbolGraph::KIND_NAME_ONLY, $followFiles);
                    }
                    return;
                }
            },
        );
    }

    /**
     * Add an edge plus, if the target class FQN resolves to a project
     * file we haven't seen, queue it for BFS.
     *
     * @param array<string, true> $followFiles in-out
     */
    private function addEdge(string $from, string $to, string $kind, array &$followFiles): void
    {
        $this->edges[$from][] = ['target' => $to, 'kind' => $kind];
        $sep = strrpos($to, '::');
        if ($sep === false) {
            return;
        }
        $classFqn = substr($to, 0, $sep);
        if ($classFqn === '' || $classFqn === '*' || $classFqn[0] === '*') {
            return;
        }
        $resolved = $this->resolveFile($classFqn);
        if ($resolved !== null) {
            $followFiles[$resolved] = true;
        }
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

    /**
     * Resolve a type-hint node (params, properties, return types) to a
     * project-class FQN string, or null when it's a builtin / union /
     * intersection / nullable scalar that can't be reduced to one class.
     */
    private function typeFqn(?Node $node): ?string
    {
        if ($node instanceof Node\NullableType) {
            return $this->typeFqn($node->type);
        }
        if ($node instanceof Identifier) {
            // builtin (int, string, mixed, …) — no class
            return null;
        }
        if ($node instanceof Name) {
            $fqn = ltrim($node->toString(), '\\');
            if ($fqn === '' || self::isReservedName($fqn)) {
                return null;
            }
            return $fqn;
        }
        // Union / intersection: bail.
        return null;
    }

    /**
     * @param array<string, string> $varTypes
     */
    private function inferType(Node $expr, array $varTypes, string $classFqn): ?string
    {
        if ($expr instanceof New_ && $expr->class instanceof Name) {
            return $expr->class->toString();
        }
        if ($expr instanceof Variable && is_string($expr->name)) {
            return $varTypes[$expr->name] ?? null;
        }
        if ($expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
        ) {
            $propName = $expr->name->toString();
            $meta = $this->classes[$classFqn] ?? null;
            return $meta['props'][$propName] ?? null;
        }
        if ($expr instanceof StaticCall && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            $target = $expr->class->toString();
            $methodName = $expr->name->toString();
            $meta = $this->classes[$target] ?? null;
            return $meta['methods'][$methodName]['returnType'] ?? null;
        }
        if ($expr instanceof MethodCall && $expr->name instanceof Identifier) {
            $methodName = $expr->name->toString();
            $recv = $this->inferType($expr->var, $varTypes, $classFqn);
            if ($recv === null) {
                return null;
            }
            $meta = $this->classes[$recv] ?? null;
            return $meta['methods'][$methodName]['returnType'] ?? null;
        }
        if ($expr instanceof ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier && $expr->name->toString() === 'class') {
            // Foo::class → string, not an object instance.
            return null;
        }

        return null;
    }

    /**
     * Visit every node in $nodes (depth-first). $visitor is called on
     * enter — return values are ignored. Closures / arrow functions
     * are entered so calls inside them participate in the graph.
     *
     * @param array<Node>|Node $nodes
     */
    private function walk(array|Node $nodes, callable $visitor): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($visitor) extends NodeVisitorAbstract {
            /** @var callable */
            private $visitor;
            public function __construct(callable $v) { $this->visitor = $v; }
            public function enterNode(Node $node): null
            {
                ($this->visitor)($node);
                if ($node instanceof Closure || $node instanceof ArrowFunction) {
                    return null; // descend
                }
                return null;
            }
        });
        $traverser->traverse(is_array($nodes) ? $nodes : [$nodes]);
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
