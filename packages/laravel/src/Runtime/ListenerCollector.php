<?php

declare(strict_types=1);

namespace Watson\Laravel\Runtime;

use Watson\Core\Discovery\ClassScanner;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * Static-discovery collector for event listeners. Laravel's auto-discovery
 * scans `App\Listeners\*` for classes whose `handle(Event $event)` method
 * type-hints an event. We mirror that: walk `app/Listeners/`, take any
 * concrete class, prefer `handle` as the handler method, fall back to
 * `__invoke`. The actual event class would be a follow-up enrichment.
 */
final class ListenerCollector
{
    /** @return list<EntryPoint> */
    public static function collect(string $appPath): array
    {
        $out = [];
        foreach (ClassScanner::scan([$appPath . '/Listeners']) as $reflection) {
            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }
            $methodName = match (true) {
                $reflection->hasMethod('handle') => 'handle',
                $reflection->hasMethod('__invoke') => '__invoke',
                default => null,
            };
            if ($methodName === null) {
                continue;
            }
            $method = $reflection->getMethod($methodName);

            $out[] = new EntryPoint(
                kind: 'laravel.listener',
                name: $reflection->getName(),
                handlerFqn: $reflection->getName() . '::' . $methodName,
                handlerPath: (string) $reflection->getFileName(),
                handlerLine: $method->getStartLine() ?: 0,
                source: Source::Interface_,
            );
        }

        return $out;
    }
}
