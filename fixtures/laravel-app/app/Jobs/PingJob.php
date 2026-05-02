<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

final class PingJob implements ShouldQueue
{
    public function handle(): void
    {
        // pong
    }
}
