<?php

declare(strict_types=1);

namespace App\Service;

final class Greeter
{
    public function format(string $name): string
    {
        return sprintf('Hello, %s!', $name);
    }
}
