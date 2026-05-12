<?php

declare(strict_types=1);

namespace Watson\Cli;

/**
 * Locate the project root by walking up from `$startDir` until a
 * `composer.json` is found. Framework picking has moved into each
 * {@see \Watson\Cli\Source\EntrypointSource}, so this is now a pure
 * "find the root" walk that never throws on missing framework markers.
 */
final class ProjectDetector
{
    public static function detect(string $startDir): Project
    {
        $real = realpath($startDir);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("project directory not found: {$startDir}");
        }

        $current = $real;
        while (true) {
            if (is_file($current . '/composer.json')) {
                return new Project($current);
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        throw new \RuntimeException(sprintf(
            'no composer.json found at or above %s; pass --project=<path>',
            $real,
        ));
    }
}
