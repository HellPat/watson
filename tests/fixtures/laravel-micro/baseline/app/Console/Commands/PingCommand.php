<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pinger;
use Illuminate\Console\Command;

final class PingCommand extends Command
{
    protected $signature = 'app:ping {--who=cli}';
    protected $description = 'Ping someone from the CLI.';

    public function __construct(private readonly Pinger $pinger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->pinger->ping('command');

        return self::SUCCESS;
    }
}
