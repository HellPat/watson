<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Greeter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pre-attribute Symfony command — uses `protected static $defaultName` to
 * register the command name. watson should detect this without `#[AsCommand]`.
 */
final class PingCommand extends Command
{
    protected static $defaultName = 'app:ping';

    public function __construct(private readonly Greeter $greeter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->greeter->format('legacy-ping'));

        return Command::SUCCESS;
    }
}
