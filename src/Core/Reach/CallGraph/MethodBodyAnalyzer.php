<?php

declare(strict_types=1);

namespace Watson\Core\Reach\CallGraph;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Walk one ClassMethod body and emit per-method outgoing edges.
 *
 * Stateless across calls — invoke `analyze()` once per method. The
 * mutable {@see TypeMap} lives only for the duration of that call.
 *
 * Edges are produced for:
 *   - StaticCall:               Foo::bar()      → Resolved   `Foo::bar`
 *   - New_:                     new Foo(…)      → Resolved   `Foo::__construct`
 *   - MethodCall (resolved):    $x->bar()       → Resolved   `<type($x)>::bar`
 *   - MethodCall (unresolved):  $x->bar()       → NameOnly   `*::bar`
 *   - ClassConstFetch:          Foo::CONST      → ClassLevel `Foo::*`
 *   - StaticPropertyFetch:      Foo::$x         → ClassLevel `Foo::*`
 *
 * Assignment statements feed the TypeMap so subsequent uses of the same
 * variable resolve correctly.
 */
final class MethodBodyAnalyzer
{
    /**
     * @param array<string, string>          $paramTypes name → FQN, seeded from the method signature
     * @return list<array{target:string,kind:string}>
     */
    public function analyze(string $classFqn, ClassMethod $method, array $paramTypes, TypeMap $typeMap): array
    {
        $typeMap->seedParams($paramTypes);

        $edges = [];
        $visitor = new class($edges, $typeMap, $this) extends NodeVisitorAbstract {
            /** @var list<array{target:string,kind:string}> */
            public array $edges;

            public function __construct(
                array &$edges,
                private readonly TypeMap $typeMap,
                private readonly MethodBodyAnalyzer $analyzer,
            ) {
                $this->edges = &$edges;
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Assign) {
                    $this->analyzer->handleAssign($node, $this->typeMap);
                    return null;
                }
                if ($node instanceof New_) {
                    $this->analyzer->handleNew($node, $this->edges);
                    return null;
                }
                if ($node instanceof StaticCall) {
                    $this->analyzer->handleStaticCall($node, $this->edges);
                    return null;
                }
                if ($node instanceof StaticPropertyFetch) {
                    $this->analyzer->handleStaticPropertyFetch($node, $this->edges);
                    return null;
                }
                if ($node instanceof ClassConstFetch) {
                    $this->analyzer->handleClassConstFetch($node, $this->edges);
                    return null;
                }
                if ($node instanceof MethodCall) {
                    $this->analyzer->handleMethodCall($node, $this->typeMap, $this->edges);
                    return null;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($method->getStmts() ?? []);

        return $visitor->edges;
    }

    public function handleAssign(Assign $node, TypeMap $typeMap): void
    {
        if (!($node->var instanceof Variable) || !is_string($node->var->name)) {
            return;
        }
        $type = $this->inferType($node->expr, $typeMap);
        if ($type !== null) {
            $typeMap->record($node->var->name, $type);
        }
    }

    /** @param list<array{target:string,kind:string}> $edges */
    public function handleNew(New_ $node, array &$edges): void
    {
        if (!($node->class instanceof Name)) {
            return;
        }
        $edges[] = ['target' => $node->class->toString() . '::__construct', 'kind' => SymbolGraph::KIND_RESOLVED];
    }

    /** @param list<array{target:string,kind:string}> $edges */
    public function handleStaticCall(StaticCall $node, array &$edges): void
    {
        if (!($node->class instanceof Name) || !($node->name instanceof Identifier)) {
            return;
        }
        $edges[] = ['target' => $node->class->toString() . '::' . $node->name->toString(), 'kind' => SymbolGraph::KIND_RESOLVED];
    }

    /** @param list<array{target:string,kind:string}> $edges */
    public function handleStaticPropertyFetch(StaticPropertyFetch $node, array &$edges): void
    {
        if (!($node->class instanceof Name)) {
            return;
        }
        $edges[] = ['target' => $node->class->toString() . '::*', 'kind' => SymbolGraph::KIND_CLASS_LEVEL];
    }

    /** @param list<array{target:string,kind:string}> $edges */
    public function handleClassConstFetch(ClassConstFetch $node, array &$edges): void
    {
        if (!($node->class instanceof Name)) {
            return;
        }
        $edges[] = ['target' => $node->class->toString() . '::*', 'kind' => SymbolGraph::KIND_CLASS_LEVEL];
    }

    /** @param list<array{target:string,kind:string}> $edges */
    public function handleMethodCall(MethodCall $node, TypeMap $typeMap, array &$edges): void
    {
        if (!($node->name instanceof Identifier)) {
            return;
        }
        $methodName = $node->name->toString();
        $receiverType = $this->inferType($node->var, $typeMap);
        if ($receiverType !== null) {
            $edges[] = ['target' => $receiverType . '::' . $methodName, 'kind' => SymbolGraph::KIND_RESOLVED];
            return;
        }
        $edges[] = ['target' => '*::' . $methodName, 'kind' => SymbolGraph::KIND_NAME_ONLY];
    }

    /**
     * Infer the FQN type of an arbitrary expression. Returns null when
     * the expression's type can't be resolved with single-pass tracking.
     */
    private function inferType(Node $expr, TypeMap $typeMap): ?string
    {
        if ($expr instanceof New_ && $expr->class instanceof Name) {
            return $expr->class->toString();
        }
        if ($expr instanceof Variable && is_string($expr->name)) {
            return $typeMap->lookup($expr->name);
        }
        if ($expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
        ) {
            return $typeMap->propType($expr->name->toString());
        }
        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            $recv = $this->inferType($expr->var, $typeMap);
            return $recv !== null ? $typeMap->propTypeOnClass($recv, $expr->name->toString()) : null;
        }
        if ($expr instanceof StaticCall && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            return $typeMap->returnType($expr->class->toString(), $expr->name->toString());
        }
        if ($expr instanceof MethodCall && $expr->name instanceof Identifier) {
            $recv = $this->inferType($expr->var, $typeMap);
            return $recv !== null ? $typeMap->returnType($recv, $expr->name->toString()) : null;
        }
        return null;
    }
}
