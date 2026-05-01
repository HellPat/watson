<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PingEvent;
use App\Services\Pinger;

/**
 * Auto-discovered Laravel listener — class lives under App\Listeners and
 * has a `handle(EventClass)` method.
 */
final class LogPing
{
    public function __construct(private readonly Pinger $pinger)
    {
    }

    public function handle(PingEvent $event): void
    {
        $this->pinger->ping('listener:'.$event->who);
    }
}
