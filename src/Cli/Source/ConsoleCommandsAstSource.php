<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;
use Watson\Core\Discovery\ConsoleCommandCollector;

/**
 * AST scan for `#[AsCommand]` / `Symfony\Component\Console\Command\Command`
 * subclasses inside the project's own PSR-4 autoload roots.
 *
 * Useful for standalone CLI tools that depend on `symfony/console` but
 * not the full Symfony framework runtime — they have no
 * `bin/console debug:container` to ask. Scan dirs come from the
 * project's `composer.json` autoload section, not a hardcoded folder
 * convention.
 */
final class ConsoleCommandsAstSource implements EntrypointSource
{
    public function name(): string
    {
        return 'console.commands.ast';
    }

    public function canHandle(Project $project): bool
    {
        // AST-scanning the project's PSR-4 roots for `Command` subclasses
        // is expensive on Laravel-sized apps (every class in `app/` gets
        // reflected). Skip the scan when a runtime front-controller is
        // present — the framework-specific runtime sources
        // ({@see SymfonyCommandsRuntimeSource}, {@see LaravelCommandsRuntimeSource})
        // already enumerate commands authoritatively. The AST scan is
        // here only as a fallback for standalone CLI tools that ship
        // neither `bin/console` nor `artisan`.
        if (is_file($project->rootPath . '/bin/console') || is_file($project->rootPath . '/artisan')) {
            return false;
        }
        return ProjectComposer::isInstalled($project, 'symfony/console');
    }

    public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
    {
        if ($opts->wantsRoutesOnly()) {
            return [];
        }
        return ConsoleCommandCollector::collect(ProjectComposer::psr4Roots($project));
    }
}
