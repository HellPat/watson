<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Greeter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GreetController
{
    public function __construct(private readonly Greeter $greeter)
    {
    }

    #[Route('/greet/{name}', name: 'greet_show', methods: ['GET'])]
    #[Route('/hello/{name}', name: 'greet_show_legacy', methods: ['GET'])]
    public function show(string $name): Response
    {
        return new Response($this->greeter->format($name));
    }
}
