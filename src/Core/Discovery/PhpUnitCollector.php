<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Treat each PHPUnit test method as a `phpunit.test` entry point. Useful
 * for blastradius: a refactor that touches a service file lights up the
 * tests that exercise it (when the test files themselves are part of the
 * diff or co-located). Handler is `<TestClass>::<testMethod>` so consumers
 * can run individual tests via `--filter`.
 *
 * Detection convention follows PHPUnit:
 *   - methods named `test*`
 *   - methods carrying `#[\PHPUnit\Framework\Attributes\Test]`
 *   - inherits from `PHPUnit\Framework\TestCase` (or any subclass)
 */
final class PhpUnitCollector
{
    /** @return list<EntryPoint> */
    public static function collect(string $testsPath): array
    {
        $out = [];
        foreach (ClassScanner::scan([$testsPath]) as $reflection) {
            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }
            if (!self::isTestCase($reflection)) {
                continue;
            }
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }
                if (!self::isTestMethod($method)) {
                    continue;
                }
                $out[] = new EntryPoint(
                    kind: 'phpunit.test',
                    name: $reflection->getShortName() . '::' . $method->getName(),
                    handlerFqn: $reflection->getName() . '::' . $method->getName(),
                    handlerPath: (string) $reflection->getFileName(),
                    handlerLine: $method->getStartLine() ?: 0,
                    source: Source::Interface_,
                );
            }
        }

        return $out;
    }

    /** @param \ReflectionClass<object> $reflection */
    private static function isTestCase(\ReflectionClass $reflection): bool
    {
        $candidate = $reflection;
        while ($candidate !== false) {
            if ($candidate->getName() === 'PHPUnit\\Framework\\TestCase') {
                return true;
            }
            $candidate = $candidate->getParentClass();
        }

        return false;
    }

    private static function isTestMethod(\ReflectionMethod $method): bool
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
