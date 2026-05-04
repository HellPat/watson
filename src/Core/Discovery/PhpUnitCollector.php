<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Discover PHPUnit test methods by parsing test files directly with
 * `nikic/php-parser` rather than walking BetterReflection's full
 * locator chain. On apps with hundreds of test files that switch is
 * the difference between sub-second and multi-minute discovery.
 *
 * A class counts as a test when it lives under the tests dir, is a
 * non-abstract `class`, and exposes either a `test*` public method or
 * a method carrying the `#[PHPUnit\Framework\Attributes\Test]`
 * attribute — the same conditions PHPUnit itself uses. We deliberately
 * skip the parent-chain check (extends `TestCase`); a class without a
 * test method or `#[Test]` is unreachable for PHPUnit anyway.
 *
 * The reflector parameter is kept for API compatibility with
 * EntrypointResolver but is not used.
 */
final class PhpUnitCollector
{
    /** @return list<EntryPoint> */
    public static function collect(string $testsDir, StaticReflector $reflector): array
    {
        if (!is_dir($testsDir)) {
            return [];
        }

        $parser = (new ParserFactory())->createForHostVersion();

        $out = [];
        foreach (self::iterPhpFiles($testsDir) as $file) {
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
            $visitor   = new TestEntryPointVisitor($file);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            foreach ($visitor->entryPoints as $ep) {
                $out[] = $ep;
            }
        }

        return $out;
    }

    /** @return iterable<string> absolute paths */
    private static function iterPhpFiles(string $dir): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                yield $f->getPathname();
            }
        }
    }
}

/**
 * Visitor pattern is the idiomatic nikic/php-parser approach: NodeTraverser
 * walks the tree once and we hook namespace + class entry/exit events here
 * to keep the FQN stack accurate without re-scanning. Cheaper than
 * NodeFinder's full-tree iterates and avoids the parent-resolution dance
 * NodeFinder needs for namespace context.
 *
 * @internal
 */
final class TestEntryPointVisitor extends NodeVisitorAbstract
{
    /** @var list<EntryPoint> */
    public array $entryPoints = [];

    private string $namespace = '';

    public function __construct(private readonly string $file)
    {
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
            return null;
        }
        if ($node instanceof Class_) {
            $this->collectFromClass($node);
        }
        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Namespace_) {
            $this->namespace = '';
        }
        return null;
    }

    private function collectFromClass(Class_ $class): void
    {
        if ($class->isAbstract()) {
            return;
        }
        $shortName = $class->name?->name;
        if ($shortName === null) {
            return;
        }
        $fqn = $this->namespace === '' ? $shortName : $this->namespace . '\\' . $shortName;

        foreach ($class->getMethods() as $method) {
            if (!$method->isPublic() || $method->isAbstract()) {
                continue;
            }
            $name = $method->name->name;
            if (!self::isTestMethod($name, $method->attrGroups)) {
                continue;
            }
            $this->entryPoints[] = new EntryPoint(
                kind: 'phpunit.test',
                name: $shortName . '::' . $name,
                handlerFqn: $fqn . '::' . $name,
                handlerPath: $this->file,
                handlerLine: $method->getStartLine() ?: 0,
                source: Source::Interface_,
            );
        }
    }

    /**
     * @param list<\PhpParser\Node\AttributeGroup> $attrGroups
     */
    private static function isTestMethod(string $name, array $attrGroups): bool
    {
        if (str_starts_with($name, 'test')) {
            return true;
        }
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->toString() === 'PHPUnit\\Framework\\Attributes\\Test') {
                    return true;
                }
            }
        }
        return false;
    }
}
