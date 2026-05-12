<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflection\ReflectionMethod;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * AST-based PhpUnit test collector. Walks the supplied directories and
 * emits one `phpunit.test` {@see EntryPoint} per concrete `TestCase`
 * subclass × per public `test*` method (or method annotated with
 * `#[PHPUnit\Framework\Attributes\Test]`).
 *
 * Subclass verification walks the full inheritance chain via Better
 * Reflection, so projects with an intermediate `AbstractTestCase` or
 * other trait-laden base classes still surface every test method.
 */
final class PhpUnitCollector
{
    private const TEST_CASE_FQN = 'PHPUnit\\Framework\\TestCase';

    /**
     * @param list<string> $dirs absolute paths to scan
     * @return list<EntryPoint>
     */
    public static function collect(array $dirs, StaticReflector $reflector): array
    {
        if ($dirs === []) {
            return [];
        }

        $out = [];
        foreach ($reflector->reflectAllInDirs($dirs) as $class) {
            if ($class->isAbstract() || $class->isInterface() || $class->isTrait()) {
                continue;
            }
            if (!self::extendsTestCase($class)) {
                continue;
            }

            $file = (string) $class->getFileName();
            if ($file === '') {
                continue;
            }
            $fqn       = $class->getName();
            $shortName = $class->getShortName();
            foreach ($class->getMethods() as $method) {
                if (!$method->isPublic() || $method->isAbstract()) {
                    continue;
                }
                $methodName = $method->getName();
                if (!self::isTestMethod($methodName, $method)) {
                    continue;
                }
                $out[] = new EntryPoint(
                    kind: 'phpunit.test',
                    name: $shortName . '::' . $methodName,
                    handlerFqn: $fqn . '::' . $methodName,
                    handlerPath: $file,
                    handlerLine: $method->getStartLine() ?: 0,
                    source: Source::Interface_,
                );
            }
        }
        return $out;
    }

    private static function extendsTestCase(ReflectionClass $class): bool
    {
        try {
            return $class->getName() === self::TEST_CASE_FQN
                || $class->isSubclassOf(self::TEST_CASE_FQN);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function isTestMethod(string $name, ReflectionMethod $method): bool
    {
        if (str_starts_with($name, 'test')) {
            return true;
        }
        try {
            foreach ($method->getAttributes() as $attr) {
                if ($attr->getName() === 'PHPUnit\\Framework\\Attributes\\Test') {
                    return true;
                }
            }
        } catch (\Throwable) {
            // tolerate broken attribute metadata; treat as "not a test"
        }
        return false;
    }
}
