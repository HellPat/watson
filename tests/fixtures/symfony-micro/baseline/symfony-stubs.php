<?php

declare(strict_types=1);

// Minimal stubs of the Symfony surface watson's fixture exercises.
// Real Symfony (vendor/) stays out of the fixture so tests are hermetic;
// this file gives mago-analyzer enough type information to resolve calls.

namespace Symfony\Component\Routing\Attribute {
    #[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
    class Route
    {
        public function __construct(
            string|array|null $path = null,
            ?string $name = null,
            ?array $methods = null,
        ) {}
    }
}

namespace Symfony\Component\Console\Attribute {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    class AsCommand
    {
        public function __construct(
            string $name,
            ?string $description = null,
        ) {}
    }
}

namespace Symfony\Component\Messenger\Attribute {
    #[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
    class AsMessageHandler
    {
    }
}

namespace Symfony\Component\Scheduler\Attribute {
    #[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
    class AsCronTask
    {
        public function __construct(
            string $expression,
            ?string $method = null,
            ?string $schedule = null,
        ) {}
    }

    #[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
    class AsPeriodicTask
    {
        public function __construct(
            string|int $frequency,
            ?string $method = null,
            ?string $schedule = null,
        ) {}
    }
}

namespace Symfony\Component\Console\Command {
    class Command
    {
        public const SUCCESS = 0;
        public const FAILURE = 1;
        public const INVALID = 2;

        public function __construct(?string $name = null)
        {
        }
    }
}

namespace Symfony\Component\Console\Input {
    interface InputInterface
    {
    }
}

namespace Symfony\Component\Console\Output {
    interface OutputInterface
    {
        public function writeln(string|iterable $messages, int $options = 0): void;
    }
}

namespace Symfony\Component\HttpFoundation {
    class Response
    {
        public function __construct(
            string $content = '',
            int $status = 200,
            array $headers = [],
        ) {}
    }
}

namespace Symfony\Bundle\FrameworkBundle\Kernel {
    trait MicroKernelTrait
    {
    }
}

namespace Symfony\Component\HttpKernel {
    class Kernel
    {
        public function __construct(string $environment, bool $debug)
        {
        }
    }
}

namespace Symfony\Component\Messenger\Handler {
    interface MessageHandlerInterface
    {
    }
}

namespace Symfony\Component\EventDispatcher {
    interface EventSubscriberInterface
    {
        public static function getSubscribedEvents(): array;
    }

    namespace Attribute {
        #[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
        class AsEventListener
        {
            public function __construct(
                ?string $event = null,
                ?string $method = null,
                int $priority = 0,
            ) {}
        }
    }
}

namespace Symfony\Component\Scheduler {
    interface ScheduleProviderInterface
    {
        public function getSchedule(): Schedule;
    }

    class Schedule
    {
    }

    namespace Attribute {
        #[\Attribute(\Attribute::TARGET_CLASS)]
        class AsSchedule
        {
            public function __construct(string $name = 'default')
            {
            }
        }
    }
}
