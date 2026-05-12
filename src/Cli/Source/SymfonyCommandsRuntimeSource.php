<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;

/**
 * Runtime Symfony Console commands via
 * `bin/console debug:container --tag=console.command`. Only applies to
 * Symfony framework apps (standalone CLI tools that just use
 * `symfony/console` go through {@see ConsoleCommandsAstSource}).
 */
final class SymfonyCommandsRuntimeSource implements EntrypointSource
{
    public function name(): string
    {
        return 'symfony.commands.runtime';
    }

    public function canHandle(Project $project): bool
    {
        return is_file($project->rootPath . '/bin/console')
            && ProjectComposer::isInstalled($project, 'symfony/framework-bundle');
    }

    public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
    {
        if ($opts->wantsRoutesOnly()) {
            return [];
        }
        return (new SymfonyConsoleSource($project, $reflector, $opts->appEnv))->commands();
    }
}
