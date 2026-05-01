<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Service\Greeter;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsPeriodicTask(frequency: '1 day')]
final class CleanupTask
{
    public function __construct(private readonly Greeter $greeter)
    {
    }

    public function __invoke(): void
    {
        $this->greeter->format('cleanup');
    }
}
