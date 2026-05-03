<?php

declare(strict_types=1);

namespace Watson\Laravel\Runtime;

use Illuminate\Contracts\Queue\ShouldQueue;
use Watson\Core\Discovery\ClassScanner;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Static-discovery collector for queued jobs. Laravel doesn't expose a
 * runtime registry (jobs are dispatched ad-hoc, not pre-registered), so
 * we scan the conventional `app/Jobs/` directory for any class
 * implementing `Illuminate\Contracts\Queue\ShouldQueue`. Handler is
 * `handle()` — Laravel's standard execution method.
 */
final class JobCollector
{
    /** @return list<EntryPoint> */
    public static function collect(string $appPath): array
    {
        $out = [];
        foreach (ClassScanner::scan([$appPath . '/Jobs']) as $reflection) {
            if (!$reflection->implementsInterface(ShouldQueue::class) || $reflection->isAbstract()) {
                continue;
            }
            [$handlerLine, $handlerFqn] = self::handler($reflection);
            $out[] = new EntryPoint(
                kind: 'laravel.job',
                name: $reflection->getName(),
                handlerFqn: $handlerFqn,
                handlerPath: (string) $reflection->getFileName(),
                handlerLine: $handlerLine,
                source: Source::Interface_,
            );
        }

        return $out;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return array{0: int, 1: string}
     */
    private static function handler(\ReflectionClass $reflection): array
    {
        if ($reflection->hasMethod('handle')) {
            $method = $reflection->getMethod('handle');

            return [$method->getStartLine() ?: 0, $reflection->getName() . '::handle'];
        }

        return [$reflection->getStartLine() ?: 0, $reflection->getName()];
    }
}
