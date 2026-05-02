<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class HelloController
{
    public function home(): string
    {
        return 'home';
    }

    public function hello(string $name): string
    {
        return "Hello, {$name}!";
    }
}
