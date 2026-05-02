<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Pinger;

/**
 * Invokable controller — Laravel routes can point at the class itself.
 */
final class PingController
{
    public function __construct(private readonly Pinger $pinger)
    {
    }

    public function __invoke(): string
    {
        return $this->pinger->ping('http');
    }
}
