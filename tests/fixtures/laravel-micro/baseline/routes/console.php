<?php

declare(strict_types=1);

use App\Jobs\PingJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('app:ping')->daily();
Schedule::job(new PingJob())->everyMinute();

Artisan::command('app:hello {name}', function (string $name) {
    // closure command body
});
