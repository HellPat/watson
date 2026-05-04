<?php

declare(strict_types=1);

namespace Watson\Cli;

final class ProjectDetector
{
    /**
     * Walk up from `$startDir` until we find a Symfony `bin/console` or
     * Laravel `artisan`. With `$forceFramework` set, only that framework's
     * entrypoint is accepted.
     */
    public static function detect(string $startDir, ?string $forceFramework = null): Project
    {
        $real = realpath($startDir);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("project directory not found: {$startDir}");
        }

        $current = $real;
        while (true) {
            $hasSymfony = is_file($current . '/bin/console');
            $hasLaravel = is_file($current . '/artisan');

            if ($hasSymfony || $hasLaravel) {
                $framework = self::pickFramework($current, $forceFramework, $hasSymfony, $hasLaravel);
                $script = $framework === Framework::Symfony ? 'bin/console' : 'artisan';
                if (!is_file($current . '/' . $script)) {
                    throw new \RuntimeException(sprintf(
                        'forced --framework=%s but %s/%s is missing',
                        $framework->value,
                        $current,
                        $script,
                    ));
                }

                return new Project($current, $framework, $script);
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

    private static function pickFramework(
        string $dir,
        ?string $force,
        bool $hasSymfony,
        bool $hasLaravel,
    ): Framework {
        if ($force !== null) {
            return match ($force) {
                'symfony' => Framework::Symfony,
                'laravel' => Framework::Laravel,
                default => throw new \RuntimeException("invalid --framework: {$force}"),
            };
        }
        if ($hasSymfony && $hasLaravel) {
            throw new \RuntimeException(sprintf(
                'both bin/console and artisan present in %s; pass --framework=symfony|laravel',
                $dir,
            ));
        }

        return $hasSymfony ? Framework::Symfony : Framework::Laravel;
    }
}
