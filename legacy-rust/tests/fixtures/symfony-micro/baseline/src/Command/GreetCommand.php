<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Greeter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:greet', description: 'Greet someone from the CLI.')]
final class GreetCommand extends Command
{
    public function __construct(private readonly Greeter $greeter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->greeter->format('CLI'));

        return Command::SUCCESS;
    }
}
