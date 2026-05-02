<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HelloController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        return new Response('home');
    }

    #[Route('/hello/{name}', name: 'hello', methods: ['GET'])]
    public function hello(string $name): Response
    {
        return new Response("Greetings, {$name}!");
    }
}
