<?php

declare(strict_types=1);

namespace App\Events;

final class PingEvent
{
    public function __construct(public readonly string $who)
    {
    }
}
