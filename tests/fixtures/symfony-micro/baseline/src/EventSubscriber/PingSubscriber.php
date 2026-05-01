<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Greeter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class PingSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Greeter $greeter)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.request' => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(): void
    {
        $this->greeter->format('subscriber');
    }
}
