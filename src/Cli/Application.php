<?php

declare(strict_types=1);

namespace Watson\Cli;

use Symfony\Component\Console\Application as ConsoleApplication;
use Watson\Cli\Command\BlastradiusCommand;
use Watson\Cli\Command\ListEntrypointsCommand;
use Watson\Core\Output\Envelope;

final class Application extends ConsoleApplication
{
    public function __construct()
    {
        parent::__construct('watson', Envelope::TOOL_VERSION);
        // Use `addCommands()` — the singular `add()` was removed in
        // Symfony Console 8 and renamed to `addCommand()`. `addCommands()`
        // is the long-standing variadic accepting both shapes across
        // 6.4 / 7.x / 8.x.
        $this->addCommands([
            new ListEntrypointsCommand(),
            new BlastradiusCommand(),
        ]);
    }
}
