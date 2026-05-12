<?php

declare(strict_types=1);

namespace Watson\Cli;

/**
 * Project root — the only thing the chain needs to know. Framework
 * picking moved into each {@see \Watson\Cli\Source\EntrypointSource}
 * (each declares its own `canHandle()`), so this value object is
 * deliberately minimal.
 */
final class Project
{
    public function __construct(
        public readonly string $rootPath,
    ) {
    }
}
