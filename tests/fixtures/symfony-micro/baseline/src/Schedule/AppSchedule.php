<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Service\Greeter;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

final class AppSchedule implements ScheduleProviderInterface
{
    public function __construct(private readonly Greeter $greeter)
    {
    }

    public function getSchedule(): Schedule
    {
        $this->greeter->format('schedule-bootstrap');

        return new Schedule();
    }
}
