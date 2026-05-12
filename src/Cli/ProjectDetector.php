<?php

declare(strict_types=1);

namespace Watson\Cli;

final class ProjectDetector
{
    /**
     * Walk up from `$startDir` until we find one of:
     *
     *  - Symfony framework  — `bin/console` is on disk;
     *  - Laravel            — `artisan` is on disk;
     *  - Standalone Symfony Console app — `composer.json` requires
     *    `symfony/console` and declares a `bin` entry, with no
     *    `bin/console` or `artisan`.
     *
     * The third case covers tools like watson itself: a CLI binary
     * that uses `symfony/console` directly but doesn't ship the full
     * framework runtime, so command discovery has to go through an
     * AST scan rather than `bin/console debug:container`.
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
            if (self::isStandaloneConsoleApp($current)) {
                return new Project($current, Framework::ConsoleApp, '');
            }

            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        throw new \RuntimeException(sprintf(
            'no Symfony bin/console, Laravel artisan, or standalone Symfony Console app (composer.json declaring symfony/console + bin) found at or above %s; pass --project=<path>',
            $real,
        ));
    }

    private static function isStandaloneConsoleApp(string $dir): bool
    {
        $composer = $dir . '/composer.json';
        if (!is_file($composer)) {
            return false;
        }
        $decoded = json_decode((string) file_get_contents($composer), true);
        if (!is_array($decoded)) {
            return false;
        }
        $require = (array) ($decoded['require'] ?? []);
        if (!isset($require['symfony/console'])) {
            return false;
        }
        return isset($decoded['bin']) && $decoded['bin'] !== [];
    }
}
