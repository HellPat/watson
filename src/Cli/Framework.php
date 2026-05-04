<?php

declare(strict_types=1);

namespace Watson\Cli;

enum Framework: string
{
    case Symfony = 'symfony';
    case Laravel = 'laravel';
}
