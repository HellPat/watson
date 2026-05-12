<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Diff;

use PHPUnit\Framework\TestCase;
use Watson\Core\Diff\AstDiffMapper;
use Watson\Core\Diff\ChangedSymbol;

final class AstDiffMapperTest extends TestCase
{
    public function testCommentOnlyEditInsideMethodEmitsNoChange(): void
    {
        $diff = <<<DIFF
diff --git a/src/Foo.php b/src/Foo.php
--- a/src/Foo.php
+++ b/src/Foo.php
@@ -1,9 +1,10 @@
 <?php
 namespace App;
 class Foo
 {
     public function bar(): int
     {
+        // brand-new comment
         return 1;
     }
 }
DIFF;
        $symbols = AstDiffMapper::map($diff, '/tmp/proj');
        self::assertSame([], $symbols);
    }

    public function testWhitespaceOnlyEditInsideMethodEmitsNoChange(): void
    {
        $diff = <<<DIFF
diff --git a/src/Foo.php b/src/Foo.php
--- a/src/Foo.php
+++ b/src/Foo.php
@@ -1,9 +1,10 @@
 <?php
 namespace App;
 class Foo
 {
     public function bar(): int
     {
-        return 1;
+        return     1;
     }
 }
DIFF;
        $symbols = AstDiffMapper::map($diff, '/tmp/proj');
        self::assertSame([], $symbols);
    }

    public function testRealBodyEditEmitsChangedSymbol(): void
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
        $symbols = AstDiffMapper::map($diff, '/tmp/proj');
        self::assertCount(1, $symbols);
        self::assertSame('App\\Foo::bar', $symbols[0]->symbol());
    }

    public function testAddedMethodEmitsChangedSymbol(): void
    {
        $diff = <<<DIFF
diff --git a/src/Foo.php b/src/Foo.php
--- a/src/Foo.php
+++ b/src/Foo.php
@@ -1,9 +1,13 @@
 <?php
 namespace App;
 class Foo
 {
     public function bar(): int
     {
         return 1;
     }
+    public function baz(): int
+    {
+        return 2;
+    }
 }
DIFF;
        $symbols = AstDiffMapper::map($diff, '/tmp/proj');
        self::assertCount(1, $symbols);
        self::assertSame('App\\Foo::baz', $symbols[0]->symbol());
    }

    public function testDeletedMethodEmitsChangedSymbol(): void
    {
        $diff = <<<DIFF
diff --git a/src/Foo.php b/src/Foo.php
--- a/src/Foo.php
+++ b/src/Foo.php
@@ -1,13 +1,9 @@
 <?php
 namespace App;
 class Foo
 {
     public function bar(): int
     {
         return 1;
     }
-    public function baz(): int
-    {
-        return 2;
-    }
 }
DIFF;
        $symbols = AstDiffMapper::map($diff, '/tmp/proj');
        self::assertCount(1, $symbols);
        self::assertSame('App\\Foo::baz', $symbols[0]->symbol());
    }

    public function testPropertyEditEmitsClassWildcard(): void
    {
        $diff = <<<DIFF
diff --git a/src/Foo.php b/src/Foo.php
--- a/src/Foo.php
+++ b/src/Foo.php
@@ -1,8 +1,8 @@
 <?php
 namespace App;
 class Foo
 {
-    private int \$count = 0;
+    private int \$count = 5;
     public function bar(): int
     {
         return 1;
     }
 }
DIFF;
        $symbols = AstDiffMapper::map($diff, '/tmp/proj');
        self::assertCount(1, $symbols);
        self::assertSame('App\\Foo::*', $symbols[0]->symbol());
    }

    public function testFilePathIsAbsolutised(): void
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
        $symbols = AstDiffMapper::map($diff, '/tmp/proj');
        self::assertCount(1, $symbols);
        self::assertSame('/tmp/proj/src/Foo.php', $symbols[0]->filePath);
    }

    public function testStripDropsLeadingPathSegments(): void
    {
        // CI runs `git diff -- 'backend/**'` from the repo root, but the
        // image's project root is `backend/`. Passing
        // `--composer-dir=backend` (which translates to stripSegments=1)
        // removes the leading `backend/` so paths resolve against the
        // right root without a sed rewrite.
        $diff = <<<DIFF
diff --git a/backend/src/Foo.php b/backend/src/Foo.php
--- a/backend/src/Foo.php
+++ b/backend/src/Foo.php
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
        $symbols = AstDiffMapper::map($diff, '/tmp/proj', stripSegments: 1);
        self::assertCount(1, $symbols);
        self::assertSame('/tmp/proj/src/Foo.php', $symbols[0]->filePath);
    }

    public function testStripSkipsFilesWithFewerSegmentsThanRequested(): void
    {
        // Path has only 1 segment ("Foo.php"); stripping 2 leaves
        // nothing, so the entry is dropped instead of resolving to the
        // project root itself.
        $diff = <<<DIFF
diff --git a/Foo.php b/Foo.php
--- a/Foo.php
+++ b/Foo.php
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
        self::assertSame([], AstDiffMapper::map($diff, '/tmp/proj', stripSegments: 2));
    }
}
