<?php

declare(strict_types=1);

namespace Watson\Cli\Reflection;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Stage a sanitized copy of the consumer's composer.json and
 * vendor/composer/installed.json with empty PSR-4 / PSR-0 prefixes
 * removed. BetterReflection's composer factory rejects empty prefixes
 * (Roave\BetterReflection\...\Psr4Mapping::assertValidMapping), but
 * real-world packages declare them — e.g. kylekatarnls/carbonite maps
 * `"" => "src/"`. Without this pass watson aborts before discovery on
 * any project that pulls such a dep.
 *
 * The staging dir holds composer.json + vendor/composer/installed.json
 * sanitized in-place; every other vendor entry is symlinked back to the
 * real tree so package path resolution keeps working. When the project
 * is already clean the original root is returned unchanged.
 */
final class ComposerProjectStaging
{
    public static function prepare(string $projectRoot): string
    {
        $composerPath  = $projectRoot . '/composer.json';
        $installedPath = $projectRoot . '/vendor/composer/installed.json';
        if (!is_file($composerPath) || !is_file($installedPath)) {
            return $projectRoot;
        }

        $composer  = self::readJson($composerPath);
        $installed = self::readJson($installedPath);
        if ($composer === null || $installed === null) {
            return $projectRoot;
        }

        $vendorDir = $projectRoot . '/vendor';
        if (!self::needsSanitize($projectRoot, $vendorDir, $composer, $installed)) {
            return $projectRoot;
        }

        $stage = sys_get_temp_dir() . '/watson-staging-' . substr(sha1($projectRoot), 0, 12);
        if (is_dir($stage)) {
            self::rmrf($stage);
        }
        if (!@mkdir($stage . '/vendor/composer', 0700, true) && !is_dir($stage . '/vendor/composer')) {
            return $projectRoot;
        }

        $sanitizedComposer = self::sanitizePackage($composer, $projectRoot);
        file_put_contents(
            $stage . '/composer.json',
            (string) json_encode($sanitizedComposer, JSON_UNESCAPED_SLASHES),
        );

        if (isset($installed['packages']) && is_array($installed['packages'])) {
            $installed['packages'] = array_map(
                static fn (array $p): array => self::sanitizePackage($p, self::packageBaseDir($vendorDir, $p)),
                $installed['packages'],
            );
        } elseif (array_is_list($installed)) {
            $installed = array_map(
                static fn (array $p): array => self::sanitizePackage($p, self::packageBaseDir($vendorDir, $p)),
                $installed,
            );
        }
        file_put_contents(
            $stage . '/vendor/composer/installed.json',
            (string) json_encode($installed, JSON_UNESCAPED_SLASHES),
        );

        // Project-root entries (e.g. `app/`, `src/`, `tests/`) are referenced
        // by PSR-4 paths in composer.json — symlink them so the factory can
        // resolve them against the staging root. composer.json is sanitized
        // above; vendor/ is handled below.
        $rootEntries = @scandir($projectRoot) ?: [];
        foreach ($rootEntries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'composer.json' || $entry === 'vendor') {
                continue;
            }
            @symlink($projectRoot . '/' . $entry, $stage . '/' . $entry);
        }

        $vendorEntries = @scandir($projectRoot . '/vendor') ?: [];
        foreach ($vendorEntries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'composer') {
                continue;
            }
            @symlink($projectRoot . '/vendor/' . $entry, $stage . '/vendor/' . $entry);
        }
        $composerEntries = @scandir($projectRoot . '/vendor/composer') ?: [];
        foreach ($composerEntries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'installed.json') {
                continue;
            }
            @symlink($projectRoot . '/vendor/composer/' . $entry, $stage . '/vendor/composer/' . $entry);
        }

        return $stage;
    }

    private static function readJson(string $path): ?array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    private static function needsSanitize(string $projectRoot, string $vendorDir, array $composer, array $installed): bool
    {
        if (self::pkgHasInvalidEntry($composer, $projectRoot)) {
            return true;
        }
        $packages = $installed['packages'] ?? (array_is_list($installed) ? $installed : []);
        foreach ($packages as $p) {
            if (is_array($p) && self::pkgHasInvalidEntry($p, self::packageBaseDir($vendorDir, $p))) {
                return true;
            }
        }
        return false;
    }

    private static function pkgHasInvalidEntry(array $pkg, string $baseDir): bool
    {
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (['psr-4', 'psr-0'] as $kind) {
                $map = $pkg[$section][$kind] ?? null;
                if (!is_array($map)) {
                    continue;
                }
                if (array_key_exists('', $map)) {
                    return true;
                }
                foreach ($map as $paths) {
                    foreach ((array) $paths as $path) {
                        if (!is_string($path)) {
                            continue;
                        }
                        if (!is_dir($baseDir . '/' . ltrim($path, '/'))) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    private static function sanitizePackage(array $pkg, string $baseDir): array
    {
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (['psr-4', 'psr-0'] as $kind) {
                $map = $pkg[$section][$kind] ?? null;
                if (!is_array($map)) {
                    continue;
                }
                unset($map['']);
                foreach ($map as $prefix => $paths) {
                    $kept = [];
                    foreach ((array) $paths as $path) {
                        if (is_string($path) && is_dir($baseDir . '/' . ltrim($path, '/'))) {
                            $kept[] = $path;
                        }
                    }
                    if ($kept === []) {
                        unset($map[$prefix]);
                    } else {
                        $map[$prefix] = is_array($paths) ? $kept : $kept[0];
                    }
                }
                if ($map === []) {
                    unset($pkg[$section][$kind]);
                } else {
                    $pkg[$section][$kind] = $map;
                }
            }
        }
        return $pkg;
    }

    private static function packageBaseDir(string $vendorDir, array $pkg): string
    {
        $installPath = $pkg['install-path'] ?? null;
        if (is_string($installPath) && $installPath !== '') {
            $candidate = realpath($vendorDir . '/composer/' . $installPath);
            if ($candidate !== false) {
                return $candidate;
            }
            return $vendorDir . '/composer/' . $installPath;
        }
        $name = $pkg['name'] ?? '';
        return $vendorDir . '/' . $name;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $f) {
            $path = $f->getPathname();
            if (is_link($path) || $f->isFile()) {
                @unlink($path);
            } elseif ($f->isDir()) {
                @rmdir($path);
            }
        }
        @rmdir($dir);
    }
}
