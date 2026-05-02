<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Pinger;
use Illuminate\Contracts\Queue\ShouldQueue;

final class PingJob implements ShouldQueue
{
    public function __construct(private readonly Pinger $pinger)
    {
    }

    public function handle(): void
    {
        $this->pinger->ping('job');
    }
}
