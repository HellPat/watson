<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * AST-based PhpUnit test collector. Yields one `phpunit.test`
 * {@see EntryPoint} per concrete `TestCase` subclass × per public
 * `test*` method (or method annotated with
 * `#[PHPUnit\Framework\Attributes\Test]`).
 *
 * Inheritance is resolved via {@see ClassIndex}'s in-project BFS —
 * intermediate `AbstractTestCase` base classes still surface every
 * test method, but we never pay BetterReflection's per-class
 * inheritance-walk cost (which on Laravel-sized trees blew past 20
 * minutes per run because every `isSubclassOf` query parsed vendor
 * files).
 */
final class PhpUnitCollector
{
    private const TEST_CASE_FQN = 'PHPUnit\\Framework\\TestCase';
    private const TEST_ATTR_FQN = 'PHPUnit\\Framework\\Attributes\\Test';

    /**
     * @param list<string> $dirs absolute paths to scan
     * @return list<EntryPoint>
     */
    public static function collect(array $dirs): array
    {
        if ($dirs === []) {
            return [];
        }
        $index = ClassIndex::buildFromDirs($dirs);
        $out = [];
        foreach ($index->all() as $entry) {
            if ($entry->isAbstract || $entry->isInterface || $entry->isTrait) {
                continue;
            }
            if (!$index->isSubclassOf($entry->fqn, self::TEST_CASE_FQN)) {
                continue;
            }
            $shortName = self::shortName($entry->fqn);
            foreach ($entry->methods as $method) {
                if (!$method->isPublic || $method->isAbstract) {
                    continue;
                }
                if (!self::isTestMethod($method->name, $method->attributeNames)) {
                    continue;
                }
                $out[] = new EntryPoint(
                    kind: 'phpunit.test',
                    name: $shortName . '::' . $method->name,
                    handlerFqn: $entry->fqn . '::' . $method->name,
                    handlerPath: $entry->file,
                    handlerLine: $method->startLine,
                    source: Source::Interface_,
                );
            }
        }
        return $out;
    }

    /** @param list<string> $attributeNames */
    private static function isTestMethod(string $name, array $attributeNames): bool
    {
        if (str_starts_with($name, 'test')) {
            return true;
        }
        foreach ($attributeNames as $attr) {
            if ($attr === self::TEST_ATTR_FQN) {
                return true;
            }
        }
        return false;
    }

    private static function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
