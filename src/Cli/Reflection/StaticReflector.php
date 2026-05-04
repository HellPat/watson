<?php

declare(strict_types=1);

namespace Watson\Cli\Reflection;

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
 * The broad locator is built from the project's composer.json + vendor/,
 * which gives us full type-resolution (parent classes, interfaces) for
 * both first-party and third-party code without ever loading it.
 */
final class StaticReflector
{
    private readonly SourceLocator $broadLocator;
    private readonly Reflector $broadReflector;
    private readonly BetterReflection $br;

    public function __construct(string $projectRoot)
    {
        $this->br = new BetterReflection();
        $composerLocator = (new MakeLocatorForComposerJsonAndInstalledJson())(
            $projectRoot,
            $this->br->astLocator(),
        );
        // Stub PHP internals (Countable, Iterator, …) so type-resolution
        // walks across user code → SPL/internals don't blow up.
        $internalLocator = new PhpInternalSourceLocator($this->br->astLocator(), $this->br->sourceStubber());
        $this->broadLocator = new AggregateSourceLocator([$composerLocator, $internalLocator]);
        $this->broadReflector = new DefaultReflector($this->broadLocator);
    }

    public function reflectClass(string $fqn): ?ReflectionClass
    {
        try {
            return $this->broadReflector->reflectClass(ltrim($fqn, '\\'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Locate a method's file + start line. Falls back to class-level when
     * the method is missing; returns empty path/line when the class can't
     * be resolved at all (e.g. closures, vendor controllers we skip).
     *
     * @return array{0: string, 1: string, 2: int} [fqn::method, file, line]
     */
    public function locateMethod(string $classFqn, string $methodName): array
    {
        $class = $this->reflectClass($classFqn);
        if ($class === null) {
            return [$classFqn . '::' . $methodName, '', 0];
        }

        $file = (string) $class->getFileName();
        $line = $class->getStartLine() ?: 0;
        try {
            $method = $class->getMethod($methodName);
            if ($method !== null) {
                $line = $method->getStartLine() ?: $line;
            }
        } catch (\Throwable) {
            // fall through with class-level line
        }

        return [$classFqn . '::' . $methodName, $file, $line];
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

        $dirLocator = new DirectoriesSourceLocator($absDirs, $this->br->astLocator());
        $aggregate = new AggregateSourceLocator([$dirLocator, $this->broadLocator]);
        $reflector = new DefaultReflector($aggregate);

        $idType = new IdentifierType(IdentifierType::IDENTIFIER_CLASS);
        foreach ($dirLocator->locateIdentifiersByType($reflector, $idType) as $identified) {
            if ($identified instanceof ReflectionClass) {
                yield $identified;
            }
        }
    }
}
