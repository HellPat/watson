<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflection\ReflectionMethod;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Treat each PHPUnit test method as a `phpunit.test` entry point. AST-only
 * via Better Reflection so loading test files never triggers their
 * boilerplate (data providers, attribute autoloaders, …).
 *
 * Detection follows PHPUnit conventions: the class extends
 * `PHPUnit\Framework\TestCase` (transitively); test methods are public,
 * declared on the class itself, and either named `test*` or carry
 * `#[\PHPUnit\Framework\Attributes\Test]`.
 */
final class PhpUnitCollector
{
    /** @return list<EntryPoint> */
    public static function collect(string $testsDir, StaticReflector $reflector): array
    {
        $out = [];
        foreach ($reflector->reflectAllInDirs([$testsDir]) as $class) {
            if ($class->isAbstract() || $class->isInterface() || $class->isTrait()) {
                continue;
            }
            if (!self::isTestCase($class)) {
                continue;
            }
            foreach ($class->getMethods() as $method) {
                if (!$method->isPublic()) {
                    continue;
                }
                if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                if (!self::isTestMethod($method)) {
                    continue;
                }
                $out[] = new EntryPoint(
                    kind: 'phpunit.test',
                    name: $class->getShortName() . '::' . $method->getName(),
                    handlerFqn: $class->getName() . '::' . $method->getName(),
                    handlerPath: (string) $class->getFileName(),
                    handlerLine: $method->getStartLine() ?: 0,
                    source: Source::Interface_,
                );
            }
        }

        return $out;
    }

    private static function isTestCase(ReflectionClass $class): bool
    {
        $cur = $class;
        while ($cur !== null) {
            if ($cur->getName() === 'PHPUnit\\Framework\\TestCase') {
                return true;
            }
            try {
                $cur = $cur->getParentClass();
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    private static function isTestMethod(ReflectionMethod $method): bool
    {
        if (str_starts_with($method->getName(), 'test')) {
            return true;
        }
        foreach ($method->getAttributes() as $attribute) {
            if ($attribute->getName() === 'PHPUnit\\Framework\\Attributes\\Test') {
                return true;
            }
        }

        return false;
    }
}
