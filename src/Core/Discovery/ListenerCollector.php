<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use Watson\Cli\Reflection\StaticReflector;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Static-discovery collector for Laravel event listeners. Walks
 * `app/Listeners/` for concrete classes that expose `handle()` (preferred)
 * or `__invoke()`. Mirrors Laravel's auto-discovery convention. AST-only
 * via Better Reflection — no autoload, no constructor side effects.
 */
final class ListenerCollector
{
    /** @return list<EntryPoint> */
    public static function collect(string $listenersDir, StaticReflector $reflector): array
    {
        $out = [];
        foreach ($reflector->reflectAllInDirs([$listenersDir]) as $class) {
            if ($class->isAbstract() || $class->isInterface() || $class->isTrait()) {
                continue;
            }

            $methodName = null;
            try {
                if ($class->hasMethod('handle')) {
                    $methodName = 'handle';
                } elseif ($class->hasMethod('__invoke')) {
                    $methodName = '__invoke';
                }
            } catch (\Throwable) {
                continue;
            }
            if ($methodName === null) {
                continue;
            }

            $line = $class->getStartLine() ?: 0;
            try {
                $method = $class->getMethod($methodName);
                if ($method !== null) {
                    $line = $method->getStartLine() ?: $line;
                }
            } catch (\Throwable) {
                // class-level fallback
            }

            $out[] = new EntryPoint(
                kind: 'laravel.listener',
                name: $class->getName(),
                handlerFqn: $class->getName() . '::' . $methodName,
                handlerPath: (string) $class->getFileName(),
                handlerLine: $line,
                source: Source::Interface_,
            );
        }

        return $out;
    }
}
