<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;

/**
 * Symfony Messenger handlers. Requires the messenger package on top
 * of the framework runtime; bails when either signal is missing.
 */
final class SymfonyMessageHandlersSource implements EntrypointSource
{
    public function name(): string
    {
        return 'symfony.message_handlers';
    }

    public function canHandle(Project $project): bool
    {
        return is_file($project->rootPath . '/bin/console')
            && ProjectComposer::isInstalled($project, 'symfony/framework-bundle')
            && ProjectComposer::isInstalled($project, 'symfony/messenger');
    }

    public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
    {
        if ($opts->wantsRoutesOnly()) {
            return [];
        }
        return (new SymfonyConsoleSource($project, $reflector, $opts->appEnv))->messageHandlers();
    }
}
