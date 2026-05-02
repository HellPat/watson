<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

/**
 * Walk a directory tree for `*.php` files and yield each declared class.
 * Tiny helper shared between Laravel / Symfony adapters that need static
 * filesystem fallback when a registry isn't available — jobs, listeners,
 * PHPUnit tests aren't surfaced by any framework runtime API in a
 * uniform way, so we rely on convention.
 *
 * The scanner doesn't autoload — `require_once` is enough because composer
 * has already wired the user's PSR-4 prefixes by the time the calling
 * console command runs. We catch `Throwable` per-file so one bad fixture
 * doesn't poison the whole pass.
 */
final class ClassScanner
{
    /**
     * @param list<string> $rootDirs absolute paths
     * @return list<\ReflectionClass<object>>
     */
    public static function scan(array $rootDirs): array
    {
        $out = [];
        foreach ($rootDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                foreach (self::reflectFile($file->getPathname()) as $reflection) {
                    $out[] = $reflection;
                }
            }
        }

        return $out;
    }

    /** @return list<\ReflectionClass<object>> */
    private static function reflectFile(string $path): array
    {
        try {
            require_once $path;
        } catch (\Throwable) {
            return [];
        }

        // Filter the full declared-class list down to those declared in
        // this exact file. Comparing realpaths avoids `..` / symlink
        // false-negatives when the class was autoloaded earlier in the
        // process (we can't rely on a before/after diff).
        $real = realpath($path);
        $out = [];
        foreach (get_declared_classes() as $class) {
            try {
                $r = new \ReflectionClass($class);
            } catch (\ReflectionException) {
                continue;
            }
            $declared = $r->getFileName();
            if ($declared === false) {
                continue;
            }
            $declaredReal = realpath($declared);
            if (($declaredReal !== false && $declaredReal === $real) || $declared === $path) {
                $out[] = $r;
            }
        }

        return $out;
    }
}
