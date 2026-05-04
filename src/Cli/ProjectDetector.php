<?php

declare(strict_types=1);

namespace Watson\Cli;

final class ProjectDetector
{
    /**
     * Walk up from `$startDir` until we find a Symfony `bin/console` or
     * Laravel `artisan`.
     */
    public static function detect(string $startDir): Project
    {
        $real = realpath($startDir);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("project directory not found: {$startDir}");
        }

        $current = $real;
        while (true) {
            $hasSymfony = is_file($current . '/bin/console');
            $hasLaravel = is_file($current . '/artisan');

            if ($hasSymfony && $hasLaravel) {
                throw new \RuntimeException(sprintf(
                    'both bin/console and artisan present in %s — ambiguous project layout',
                    $current,
                ));
            }
            if ($hasSymfony) {
                return new Project($current, Framework::Symfony, 'bin/console');
            }
            if ($hasLaravel) {
                return new Project($current, Framework::Laravel, 'artisan');
            }

            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        throw new \RuntimeException(sprintf(
            'no Symfony bin/console or Laravel artisan found at or above %s; pass --project=<path>',
            $real,
        ));
    }
}
