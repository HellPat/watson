<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use Watson\Cli\Reflection\StaticReflector;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Static-discovery collector for Laravel queued jobs. Walks `app/Jobs/`
 * and yields any concrete class implementing
 * `Illuminate\Contracts\Queue\ShouldQueue`. Discovery is AST-only via
 * Better Reflection — we never `require_once` user code.
 */
final class JobCollector
{
    /** @return list<EntryPoint> */
    public static function collect(string $jobsDir, StaticReflector $reflector): array
    {
        $out = [];
        foreach ($reflector->reflectAllInDirs([$jobsDir]) as $class) {
            if ($class->isAbstract() || $class->isInterface() || $class->isTrait()) {
                continue;
            }
            try {
                if (!$class->implementsInterface('Illuminate\\Contracts\\Queue\\ShouldQueue')) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $line = $class->getStartLine() ?: 0;
            $handlerFqn = $class->getName();
            try {
                $handle = $class->getMethod('handle');
                if ($handle !== null) {
                    $line = $handle->getStartLine() ?: $line;
                    $handlerFqn = $class->getName() . '::handle';
                }
            } catch (\Throwable) {
                // class-level fallback
            }

            $out[] = new EntryPoint(
                kind: 'laravel.job',
                name: $class->getName(),
                handlerFqn: $handlerFqn,
                handlerPath: (string) $class->getFileName(),
                handlerLine: $line,
                source: Source::Interface_,
            );
        }

        return $out;
    }
}
