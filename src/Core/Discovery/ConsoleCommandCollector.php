<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use Watson\Cli\Reflection\StaticReflector;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * AST-based Symfony Console command collector. Used by the
 * standalone-console-app resolver because such projects have no
 * `bin/console debug:container --tag=` to ask at runtime.
 *
 * Yields any concrete class that either
 *
 *   - extends `Symfony\Component\Console\Command\Command`, OR
 *   - carries the `#[AsCommand]` attribute.
 *
 * Pure AST via {@see StaticReflector} — never `require_once`s user code.
 *
 * Directories to scan are *not* hard-coded. Callers (see
 * {@see \Watson\Cli\ConsoleAppEntrypointResolver}) derive them from the
 * project's own `composer.json` PSR-4 autoload roots so we follow the
 * project's declared layout rather than guess at conventions.
 */
final class ConsoleCommandCollector
{
    private const COMMAND_BASE    = 'Symfony\\Component\\Console\\Command\\Command';
    private const AS_COMMAND_ATTR = 'Symfony\\Component\\Console\\Attribute\\AsCommand';

    /**
     * @param list<string> $dirs absolute paths
     * @return list<EntryPoint>
     */
    public static function collect(array $dirs, StaticReflector $reflector): array
    {
        $out = [];
        foreach ($reflector->reflectAllInDirs($dirs) as $class) {
            if ($class->isAbstract() || $class->isInterface() || $class->isTrait()) {
                continue;
            }
            if (!self::isCommand($class)) {
                continue;
            }

            $name = self::commandName($class) ?? $class->getName();
            $line = $class->getStartLine() ?: 0;
            $handlerFqn = $class->getName();
            try {
                $execute = $class->getMethod('execute');
                if ($execute !== null) {
                    $line = $execute->getStartLine() ?: $line;
                    $handlerFqn = $class->getName() . '::execute';
                }
            } catch (\Throwable) {
                // class-level fallback
            }

            $out[] = new EntryPoint(
                kind: 'symfony.command',
                name: $name,
                handlerFqn: $handlerFqn,
                handlerPath: (string) $class->getFileName(),
                handlerLine: $line,
                source: Source::Interface_,
            );
        }

        return $out;
    }

    private static function isCommand(\Roave\BetterReflection\Reflection\ReflectionClass $class): bool
    {
        try {
            if ($class->isSubclassOf(self::COMMAND_BASE) || $class->getName() === self::COMMAND_BASE) {
                return true;
            }
        } catch (\Throwable) {
            // fall through to attribute check
        }
        return self::commandName($class) !== null;
    }

    /**
     * Pull the `name` argument off a `#[AsCommand]` attribute. Symfony
     * accepts both `#[AsCommand('foo')]` and `#[AsCommand(name: 'foo')]`,
     * so we check the named form first and fall back to the first
     * positional argument.
     */
    private static function commandName(\Roave\BetterReflection\Reflection\ReflectionClass $class): ?string
    {
        try {
            $attrs = $class->getAttributes();
        } catch (\Throwable) {
            return null;
        }
        foreach ($attrs as $attr) {
            if ($attr->getName() !== self::AS_COMMAND_ATTR) {
                continue;
            }
            try {
                $args = $attr->getArguments();
            } catch (\Throwable) {
                continue;
            }
            if (isset($args['name']) && is_string($args['name']) && $args['name'] !== '') {
                return $args['name'];
            }
            if (isset($args[0]) && is_string($args[0]) && $args[0] !== '') {
                return $args[0];
            }
        }
        return null;
    }
}
