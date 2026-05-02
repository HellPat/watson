<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\Ping;
use App\Service\Greeter;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Pre-attribute message handler — implements MessageHandlerInterface.
 * watson should detect this without `#[AsMessageHandler]`.
 */
final class LegacyHandler implements MessageHandlerInterface
{
    public function __construct(private readonly Greeter $greeter)
    {
    }

    public function __invoke(Ping $message): void
    {
        $this->greeter->format($message->name);
    }
}
