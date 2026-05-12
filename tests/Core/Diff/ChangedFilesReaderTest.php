<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Diff;

use PHPUnit\Framework\TestCase;
use Watson\Core\Diff\ChangedFilesReader;

final class ChangedFilesReaderTest extends TestCase
{
    public function testReadsUnifiedDiffStreamIntoChangedSymbols(): void
    {
        $diff = <<<DIFF
diff --git a/src/Foo.php b/src/Foo.php
--- a/src/Foo.php
+++ b/src/Foo.php
@@ -1,9 +1,9 @@
 <?php
 namespace App;
 class Foo
 {
     public function bar(): int
     {
-        return 1;
+        return 2;
     }
 }
DIFF;
        $stream = self::stream($diff);
        $symbols = ChangedFilesReader::readUnifiedDiffSymbols($stream, '/tmp/proj');
        fclose($stream);

        self::assertCount(1, $symbols);
        self::assertSame('App\\Foo::bar', $symbols[0]->symbol());
    }

    public function testEmptyStreamReturnsEmptyList(): void
    {
        $stream = self::stream('');
        $symbols = ChangedFilesReader::readUnifiedDiffSymbols($stream, '/tmp/proj');
        fclose($stream);

        self::assertSame([], $symbols);
    }

    /** @return resource */
    private static function stream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, $contents);
        rewind($stream);
        return $stream;
    }
}
