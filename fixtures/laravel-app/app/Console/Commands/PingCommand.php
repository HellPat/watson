<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class PingCommand extends Command
{
    protected $signature = 'app:ping {--who=cli}';
    protected $description = 'Ping someone from the CLI.';

    public function handle(): int
    {
        $this->info('pong');

        return self::SUCCESS;
    }
}
