<?php

declare(strict_types=1);

namespace App\Service;

final class Greeter
{
    public function format(string $name): string
    {
        // edited: emphasised + uppercased
        return sprintf('Greetings, %s!!!', strtoupper($name));
    }
}
