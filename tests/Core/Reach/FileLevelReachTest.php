<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Reach;

use PHPUnit\Framework\TestCase;
use Watson\Core\Diff\ChangedSymbol;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;
use Watson\Core\Reach\FileLevelReach;

final class FileLevelReachTest extends TestCase
{
    public function testHitsEntryPointsWhoseFileIsInDiff(): void
    {
        $tmp = sys_get_temp_dir() . '/watson_reach_' . uniqid();
        mkdir($tmp);
        $a = $tmp . '/A.php';
        $b = $tmp . '/B.php';
        file_put_contents($a, '<?php');
        file_put_contents($b, '<?php');

        try {
            $entryPoints = [
                self::ep($a),
                self::ep($b),
                self::ep($tmp . '/Untouched.php'),
            ];

            $hits = FileLevelReach::affectedIndices($entryPoints, [self::cs($a)]);

            $this->assertSame([0], $hits);
        } finally {
            @unlink($a);
            @unlink($b);
            @rmdir($tmp);
        }
    }

    public function testEmptyDiffYieldsNoHits(): void
    {
        $entryPoints = [self::ep('/nonexistent/X.php')];
        $this->assertSame([], FileLevelReach::affectedIndices($entryPoints, []));
    }

    public function testNonExistentDeletedFilePathStillMatches(): void
    {
        $deleted = '/abs/path/Deleted.php';
        $entryPoints = [self::ep($deleted)];

        // Deleted files in the diff have no realpath; reach must still
        // match them by raw string so blastradius reports them.
        $this->assertSame([0], FileLevelReach::affectedIndices($entryPoints, [self::cs($deleted)]));
    }

    private static function cs(string $path): ChangedSymbol
    {
        return new ChangedSymbol($path, null, null, 1, 1);
    }

    private static function ep(string $path): EntryPoint
    {
        return new EntryPoint(
            kind: 'symfony.route',
            name: 'r',
            handlerFqn: 'X::y',
            handlerPath: $path,
            handlerLine: 1,
            source: Source::Runtime,
        );
    }
}
