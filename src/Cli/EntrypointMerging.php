<?php

declare(strict_types=1);

namespace Watson\Cli;

use Watson\Core\Entrypoint\EntryPoint;

/**
 * Helpers shared by the framework-specific entry-point resolvers.
 *
 * Kept as a tiny static utility instead of a base class so each
 * resolver stays a small, self-contained façade without inheritance
 * coupling.
 */
final class EntrypointMerging
{
    /**
     * Directories to AST-scan for Symfony Console command subclasses.
     * Covers the Symfony default (`src/`), Laravel's
     * `app/Console/Commands`, and any extra location callers add via
     * `--project=…`. Non-existent paths are dropped.
     *
     * @return list<string>
     */
    public static function commandScanDirs(string $rootPath): array
    {
        $candidates = [
            $rootPath . '/src',
            $rootPath . '/app/Console/Commands',
        ];
        return array_values(array_filter($candidates, 'is_dir'));
    }

    /**
     * Drop later entry points whose `handlerFqn` was already seen, so
     * runtime + AST command discovery can be merged without
     * double-listing the same command.
     *
     * @param list<EntryPoint> $entryPoints
     * @return list<EntryPoint>
     */
    public static function dedupByHandlerFqn(array $entryPoints): array
    {
        $seen = [];
        $out = [];
        foreach ($entryPoints as $entryPoint) {
            if (isset($seen[$entryPoint->handlerFqn])) {
                continue;
            }
            $seen[$entryPoint->handlerFqn] = true;
            $out[] = $entryPoint;
        }
        return $out;
    }
}
