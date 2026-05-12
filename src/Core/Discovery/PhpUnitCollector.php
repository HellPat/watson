<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Yields one `phpunit.test` {@see EntryPoint} per concrete `TestCase`
 * subclass × per public `test*` method (or method annotated with
 * `#[PHPUnit\Framework\Attributes\Test]`).
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
        foreach ($index->concreteSubclassesOf(self::TEST_CASE_FQN) as $entry) {
            $shortName = $entry->shortName();
            foreach ($entry->methods as $method) {
                if (!$method->isPublic || $method->isAbstract) {
                    continue;
                }
                if (!self::isTestMethod($method->name, $method->attributeArgs)) {
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

    /** @param array<string, list<scalar|null>> $attributeArgs */
    private static function isTestMethod(string $name, array $attributeArgs): bool
    {
        return str_starts_with($name, 'test') || isset($attributeArgs[self::TEST_ATTR_FQN]);
    }
}
