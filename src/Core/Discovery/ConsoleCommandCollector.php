<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * AST-based Symfony Console command collector for standalone CLI tools
 * (no `bin/console debug:container` to ask). Yields any concrete class
 * that either extends `Symfony\Component\Console\Command\Command` or
 * carries the `#[AsCommand]` attribute.
 *
 * Directories to scan are *not* hard-coded — callers derive them from
 * the project's own `composer.json` PSR-4 autoload roots so we follow
 * the project's declared layout rather than guess at conventions.
 */
final class ConsoleCommandCollector
{
    private const COMMAND_BASE    = 'Symfony\\Component\\Console\\Command\\Command';
    private const AS_COMMAND_ATTR = 'Symfony\\Component\\Console\\Attribute\\AsCommand';

    /**
     * @param list<string> $dirs absolute paths
     * @return list<EntryPoint>
     */
    public static function collect(array $dirs): array
    {
        if ($dirs === []) {
            return [];
        }
        $index = ClassIndex::buildFromDirs($dirs);
        $out = [];
        foreach ($index->concreteSubclassesOf(self::COMMAND_BASE, self::AS_COMMAND_ATTR) as $entry) {
            $execute    = $entry->methods['execute'] ?? null;
            $handlerFqn = $execute !== null ? $entry->fqn . '::execute' : $entry->fqn;
            $line       = $execute !== null ? ($execute->startLine ?: $entry->startLine) : $entry->startLine;

            $out[] = new EntryPoint(
                kind: 'symfony.command',
                name: self::commandNameFromAttribute($entry) ?? $entry->fqn,
                handlerFqn: $handlerFqn,
                handlerPath: $entry->file,
                handlerLine: $line,
                source: Source::Interface_,
            );
        }
        return $out;
    }

    /**
     * Symfony accepts both `#[AsCommand('foo')]` and
     * `#[AsCommand(name: 'foo')]`. `name` is the first parameter in
     * either form, so the first string in the attribute's recorded args
     * is the command name.
     */
    private static function commandNameFromAttribute(ClassEntry $entry): ?string
    {
        foreach ($entry->attributeArgs[self::AS_COMMAND_ATTR] ?? [] as $arg) {
            if (is_string($arg) && $arg !== '') {
                return $arg;
            }
        }
        return null;
    }
}
