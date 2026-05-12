<?php

declare(strict_types=1);

namespace Watson\Cli;

enum Framework: string
{
    case Symfony = 'symfony';
    case Laravel = 'laravel';

    /**
     * Standalone Symfony Console application: depends on `symfony/console`
     * but has no Symfony framework runtime, no `bin/console`, and so no
     * `debug:container --tag=` for command discovery.
     */
    case ConsoleApp = 'console-app';
}
