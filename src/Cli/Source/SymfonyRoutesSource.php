<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;

/**
 * Runtime route source for Symfony framework apps. Defers the real
 * work to {@see SymfonyConsoleSource::routes()} (which shells out to
 * `bin/console debug:router --format=json`).
 */
final class SymfonyRoutesSource implements EntrypointSource
{
    public function name(): string
    {
        return 'symfony.routes';
    }

    public function canHandle(Project $project): bool
    {
        return is_file($project->rootPath . '/bin/console')
            && ProjectComposer::isInstalled($project, 'symfony/framework-bundle');
    }

    public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
    {
        return (new SymfonyConsoleSource($project, $reflector, $opts->appEnv))->routes();
    }
}
