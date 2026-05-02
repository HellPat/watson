<?php

declare(strict_types=1);

namespace App\Services;

final class Pinger
{
    public function ping(string $who): string
    {
        return sprintf('pong from %s', $who);
    }
}
