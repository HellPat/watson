<?php

declare(strict_types=1);

namespace Watson\Cli\Source;

use Watson\Cli\Project;

/**
 * Composer-runtime introspection for the *consumer* project — not for
 * watson's own vendor dir.
 *
 * Two data sources:
 *
 *   - `vendor/composer/installed.json` — the authoritative list of what
 *     actually resolved at install time. Used for "is package X here?"
 *     so we honour `replace`, `provide`, platform reqs, etc., the same
 *     way Composer's own {@see \Composer\InstalledVersions} would.
 *   - `composer.json` — the project's manifest. Used for autoload-root
 *     discovery (psr-4 paths the project itself declares).
 *
 * Both files are memoised per `rootPath` so repeated `canHandle()`
 * calls across sources only hit disk once.
 */
final class ProjectComposer
{
    /** @var array<string, ?array<string,mixed>> */
    private static array $composerJsonCache = [];
    /** @var array<string, array<string, true>> */
    private static array $installedCache = [];

    /**
     * `true` when the consumer's vendor dir contains `$package` (a
     * direct require, transitive dep, replace, or provide). Falls back
     * to the composer.json `require` / `require-dev` lists when no
     * `vendor/composer/installed.json` exists (uninstalled checkout).
     */
    public static function isInstalled(Project $project, string $package): bool
    {
        $installed = self::installed($project);
        if ($installed !== null) {
            return isset($installed[$package]);
        }
        $composer = self::composerJson($project);
        if ($composer === null) {
            return false;
        }
        return isset($composer['require'][$package]) || isset($composer['require-dev'][$package]);
    }

    /**
     * Absolute paths of every directory declared under the project's
     * own `autoload.psr-4` + `autoload-dev.psr-4`. Vendor packages'
     * autoload sections are intentionally excluded — we only care about
     * the consumer's first-party code.
     *
     * @return list<string>
     */
    public static function psr4Roots(Project $project): array
    {
        $composer = self::composerJson($project);
        if ($composer === null) {
            return [];
        }
        $dirs = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $psr4 = $composer[$section]['psr-4'] ?? null;
            if (!is_array($psr4)) {
                continue;
            }
            foreach ($psr4 as $paths) {
                foreach ((array) $paths as $rel) {
                    if (!is_string($rel) || $rel === '') {
                        continue;
                    }
                    $abs = rtrim($project->rootPath, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR
                        . ltrim($rel, DIRECTORY_SEPARATOR);
                    if (is_dir($abs)) {
                        $dirs[$abs] = true;
                    }
                }
            }
        }
        return array_keys($dirs);
    }

    /** @return array<string,mixed>|null */
    private static function composerJson(Project $project): ?array
    {
        $path = $project->rootPath . '/composer.json';
        if (array_key_exists($path, self::$composerJsonCache)) {
            return self::$composerJsonCache[$path];
        }
        if (!is_file($path)) {
            return self::$composerJsonCache[$path] = null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return self::$composerJsonCache[$path] = is_array($decoded) ? $decoded : null;
    }

    /**
     * Read `vendor/composer/installed.json` (Composer 2 schema:
     * `{"packages": [{"name": "...", ...}], "dev": false}`) and return
     * the union of installed package names, including everything they
     * `replace` / `provide`. Returns `null` when the file is missing —
     * caller should fall back to the manifest.
     *
     * @return array<string, true>|null
     */
    private static function installed(Project $project): ?array
    {
        $path = $project->rootPath . '/vendor/composer/installed.json';
        if (isset(self::$installedCache[$path])) {
            return self::$installedCache[$path];
        }
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }
        $packages = $decoded['packages'] ?? $decoded; // composer 2 vs 1 fallback
        if (!is_array($packages)) {
            return null;
        }
        $set = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $name = $package['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $set[$name] = true;
            }
            foreach (['replace', 'provide'] as $kind) {
                $alts = $package[$kind] ?? null;
                if (is_array($alts)) {
                    foreach (array_keys($alts) as $alt) {
                        if (is_string($alt) && $alt !== '') {
                            $set[$alt] = true;
                        }
                    }
                }
            }
        }
        return self::$installedCache[$path] = $set;
    }
}
