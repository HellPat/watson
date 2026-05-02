<?php

declare(strict_types=1);

namespace Watson\Core\Tests\Entrypoint;

use PHPUnit\Framework\TestCase;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

final class EntryPointTest extends TestCase
{
    public function testSerialisationOmitsEmptyExtra(): void
    {
        $ep = new EntryPoint(
            kind: 'symfony.route',
            name: 'home',
            handlerFqn: 'App\\HomeController::index',
            handlerPath: '/abs/HomeController.php',
            handlerLine: 12,
            source: Source::Runtime,
        );

        $payload = $ep->jsonSerialize();

        $this->assertSame('symfony.route', $payload['kind']);
        $this->assertSame('home', $payload['name']);
        $this->assertSame('runtime', $payload['source']);
        $this->assertArrayNotHasKey('extra', $payload);
    }

    public function testSerialisationKeepsExtraWhenPopulated(): void
    {
        $ep = new EntryPoint(
            kind: 'laravel.route',
            name: 'show',
            handlerFqn: 'App\\Controller::show',
            handlerPath: '',
            handlerLine: 0,
            source: Source::Runtime,
            extra: ['path' => '/users/{id}', 'methods' => ['GET']],
        );

        $payload = $ep->jsonSerialize();

        $this->assertSame(['path' => '/users/{id}', 'methods' => ['GET']], $payload['extra']);
    }
}
