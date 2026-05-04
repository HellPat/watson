<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
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
 * attribute. Classes named *Test / *TestCase without any test methods
 * are skipped — PHPUnit wouldn't run them either.
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
        $finder = new NodeFinder();

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
            foreach ($finder->findInstanceOf($ast, Class_::class) as $class) {
                /** @var Class_ $class */
                if ($class->isAbstract()) {
                    continue;
                }
                $shortName = $class->name?->name;
                if ($shortName === null) {
                    continue;
                }
                $namespace = self::namespaceOf($ast, $class);
                $fqn       = $namespace === '' ? $shortName : $namespace . '\\' . $shortName;

                foreach (self::collectTestMethods($class) as [$methodName, $line]) {
                    $out[] = new EntryPoint(
                        kind: 'phpunit.test',
                        name: $shortName . '::' . $methodName,
                        handlerFqn: $fqn . '::' . $methodName,
                        handlerPath: $file,
                        handlerLine: $line,
                        source: Source::Interface_,
                    );
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array{0: string, 1: int}> [method name, declaration line]
     */
    private static function collectTestMethods(Class_ $class): array
    {
        $methods = [];
        foreach ($class->getMethods() as $method) {
            if (!$method->isPublic() || $method->isAbstract()) {
                continue;
            }
            $name = $method->name->name;
            $isTest = str_starts_with($name, 'test');
            if (!$isTest) {
                foreach ($method->attrGroups as $group) {
                    foreach ($group->attrs as $attr) {
                        if ($attr->name->toString() === 'PHPUnit\\Framework\\Attributes\\Test') {
                            $isTest = true;
                            break 2;
                        }
                    }
                }
            }
            if ($isTest) {
                $methods[] = [$name, $method->getStartLine() ?: 0];
            }
        }
        return $methods;
    }

    /**
     * @param list<Node> $ast
     */
    private static function namespaceOf(array $ast, Class_ $needle): string
    {
        foreach ($ast as $stmt) {
            if (!$stmt instanceof Namespace_) {
                continue;
            }
            foreach ($stmt->stmts as $inner) {
                if ($inner === $needle) {
                    return $stmt->name?->toString() ?? '';
                }
            }
        }
        return '';
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
