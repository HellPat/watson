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
        $this->add(new ListEntrypointsCommand());
        $this->add(new BlastradiusCommand());
    }
}
