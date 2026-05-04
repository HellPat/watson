<?php

declare(strict_types=1);

namespace Watson\Cli\Reflection;

use Composer\Autoload\ClassLoader;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Identifier\IdentifierType;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflector\DefaultReflector;
use Roave\BetterReflection\Reflector\Reflector;
use Roave\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\Composer\Factory\MakeLocatorForComposerJsonAndInstalledJson;
use Roave\BetterReflection\SourceLocator\Type\DirectoriesSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\PhpInternalSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\SourceLocator;

/**
 * Static reflection wrapper around Roave Better Reflection. Reads the
 * project's source via `nikic/php-parser` — no `require_once`, no
 * autoloader registration, no constructor side-effects on user code.
 *
 * Two implementation tracks coexist:
 *
 *  - **Fast path** for `locateMethod()` — uses Composer's own ClassLoader
 *    to resolve a class FQN to its file (an O(1) hash lookup against the
 *    classmap + PSR-4 trie) and a single nikic/php-parser pass to find
 *    the method line. Used by the route / command sources where we know
 *    the FQN exactly and only need a path:line tuple.
 *
 *  - **BetterReflection path** for `reflectClass()` / `reflectAllInDirs()`
 *    — kept because the broader interface walks (parent class, interface
 *    chain, declared methods, attributes) need a real reflection API.
 *    Built lazily on first use so source enumeration that only calls
 *    `locateMethod` doesn't pay BetterReflection's start-up tax.
 */
final class StaticReflector
{
    private readonly string $projectRoot;
    private readonly ?ClassLoader $classLoader;
    private ?BetterReflection $br = null;
    private ?SourceLocator $broadLocator = null;
    private ?Reflector $broadReflector = null;
    private ?Parser $parser = null;
    /** @var array<string, array{0: string, 1: array<string, int>}> file → [namespace, method-name → line] */
    private array $methodLineCache = [];

    public function __construct(string $projectRoot, ?ClassLoader $classLoader = null)
    {
        $this->projectRoot = $projectRoot;
        $this->classLoader = $classLoader;
    }

    public function reflectClass(string $fqn): ?ReflectionClass
    {
        try {
            return $this->reflector()->reflectClass(ltrim($fqn, '\\'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Locate a method's file + start line. Returns empty path/line when
     * the class can't be resolved at all (e.g. closures, vendor classes
     * the autoloader doesn't know about).
     *
     * @return array{0: string, 1: string, 2: int} [fqn::method, file, line]
     */
    public function locateMethod(string $classFqn, string $methodName): array
    {
        $fqn = ltrim($classFqn, '\\');
        $handlerFqn = $fqn . '::' . $methodName;

        $file = $this->classLoader?->findFile($fqn) ?: null;
        if (is_string($file) && is_file($file)) {
            $line = $this->methodLineFromAst($file, $fqn, $methodName);
            return [$handlerFqn, $file, $line];
        }

        // Fall back to BetterReflection when ClassLoader doesn't know the
        // class (anonymous / generated / vendor not in autoload).
        $class = $this->reflectClass($fqn);
        if ($class === null) {
            return [$handlerFqn, '', 0];
        }
        $resolved = (string) $class->getFileName();
        $line     = $class->getStartLine() ?: 0;
        try {
            $method = $class->getMethod($methodName);
            if ($method !== null) {
                $line = $method->getStartLine() ?: $line;
            }
        } catch (\Throwable) {
        }
        return [$handlerFqn, $resolved, $line];
    }

    /**
     * Iterate every class declared in `$dirs`. Type resolution (parents,
     * interfaces) still works because the broad locator is included in
     * the aggregate.
     *
     * @param list<string> $dirs absolute paths
     * @return iterable<ReflectionClass>
     */
    public function reflectAllInDirs(array $dirs): iterable
    {
        $absDirs = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $real = realpath($dir);
            if ($real !== false) {
                $absDirs[] = $real;
            }
        }
        if ($absDirs === []) {
            return;
        }

        $reflector  = $this->reflector(); // ensures locators are built
        $dirLocator = new DirectoriesSourceLocator($absDirs, $this->br()->astLocator());
        $aggregate  = new AggregateSourceLocator([$dirLocator, $this->broadLocator()]);
        $scoped     = new DefaultReflector($aggregate);

        $idType = new IdentifierType(IdentifierType::IDENTIFIER_CLASS);
        foreach ($dirLocator->locateIdentifiersByType($scoped, $idType) as $identified) {
            if ($identified instanceof ReflectionClass) {
                yield $identified;
            }
        }
    }

    private function methodLineFromAst(string $file, string $classFqn, string $methodName): int
    {
        if (!isset($this->methodLineCache[$file])) {
            $code = @file_get_contents($file);
            if (!is_string($code)) {
                return 0;
            }
            try {
                $ast = $this->parser()->parse($code);
            } catch (\Throwable) {
                return 0;
            }
            if ($ast === null) {
                return 0;
            }
            $visitor = new MethodLineVisitor();
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            $this->methodLineCache[$file] = $visitor->methodLines;
        }

        $entry = $this->methodLineCache[$file];
        if (isset($entry[$classFqn][$methodName])) {
            return $entry[$classFqn][$methodName];
        }
        // Fall back to class-level start line when the method isn't found.
        return $entry[$classFqn]['__class__'] ?? 0;
    }

    private function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForHostVersion();
    }

    private function br(): BetterReflection
    {
        return $this->br ??= new BetterReflection();
    }

    private function broadLocator(): SourceLocator
    {
        if ($this->broadLocator !== null) {
            return $this->broadLocator;
        }
        // Some packages (e.g. kylekatarnls/carbonite) declare an empty PSR-4
        // prefix mapping which BetterReflection's factory rejects outright.
        // ComposerProjectStaging strips those entries before the factory sees them.
        $composerLocator = (new MakeLocatorForComposerJsonAndInstalledJson())(
            ComposerProjectStaging::prepare($this->projectRoot),
            $this->br()->astLocator(),
        );
        // Stub PHP internals (Countable, Iterator, …) so type-resolution
        // walks across user code → SPL/internals don't blow up.
        $internalLocator = new PhpInternalSourceLocator($this->br()->astLocator(), $this->br()->sourceStubber());
        return $this->broadLocator = new AggregateSourceLocator([$composerLocator, $internalLocator]);
    }

    private function reflector(): Reflector
    {
        return $this->broadReflector ??= new DefaultReflector($this->broadLocator());
    }
}

/**
 * Walks an AST once, capturing per-class method declaration lines keyed by
 * fully-qualified class name. Powers {@see StaticReflector::locateMethod}'s
 * fast path so a single file yields every method's line in one pass.
 *
 * @internal
 */
final class MethodLineVisitor extends NodeVisitorAbstract
{
    /** @var array<string, array<string, int>> classFqn → method-name → line (with `__class__` for the class start line) */
    public array $methodLines = [];

    private string $namespace = '';

    public function enterNode(Node $node): null
    {
        if ($node instanceof Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
            return null;
        }
        if ($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_) {
            $name = $node->name?->name;
            if ($name === null) {
                return null;
            }
            $fqn = $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
            $entries = ['__class__' => $node->getStartLine() ?: 0];
            foreach ($node->getMethods() as $method) {
                $entries[$method->name->name] = $method->getStartLine() ?: 0;
            }
            $this->methodLines[$fqn] = $entries;
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
}
