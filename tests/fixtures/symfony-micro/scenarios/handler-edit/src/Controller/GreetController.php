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
    public function show(string $name): Response
    {
        // edited: appended trailer in handler body
        $body = $this->greeter->format($name);

        return new Response($body."\n[handler-edit]\n");
    }
}
