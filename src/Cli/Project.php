<?php

declare(strict_types=1);

namespace Watson\Cli;

final class Project
{
    public function __construct(
        public readonly string $rootPath,
        public readonly Framework $framework,
        /** Relative path to the framework console entrypoint, e.g. `bin/console` or `artisan`. */
        public readonly string $consoleScript,
    ) {
    }
}
