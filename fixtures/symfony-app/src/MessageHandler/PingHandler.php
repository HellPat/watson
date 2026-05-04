<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PingMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PingHandler
{
    public function __invoke(PingMessage $message): void
    {
        // no-op: fixture only exists so messenger has a tagged service.
    }
}
