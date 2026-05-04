<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Diff;

use PHPUnit\Framework\TestCase;
use Watson\Core\Diff\ChangedFilesReader;

final class ChangedFilesReaderTest extends TestCase
{
    public function testReadNameOnlySkipsBlanksAndComments(): void
    {
        $stream = self::stream(<<<TXT
        # leading comment
        app/Foo.php

        app/Bar.php
        # trailing comment
        TXT);

        $files = ChangedFilesReader::readNameOnly($stream, '/abs/root');

        $this->assertSame(
            ['/abs/root/app/Foo.php', '/abs/root/app/Bar.php'],
            $files,
        );
    }

    public function testReadNameOnlyKeepsAbsolutePathsAsGiven(): void
    {
        $stream = self::stream("/already/absolute.php\nrelative.php\n");

        $files = ChangedFilesReader::readNameOnly($stream, '/abs/root');

        $this->assertSame(
            ['/already/absolute.php', '/abs/root/relative.php'],
            $files,
        );
    }

    public function testReadNameOnlyDedupes(): void
    {
        $stream = self::stream("a.php\na.php\nb.php\n");

        $files = ChangedFilesReader::readNameOnly($stream, '/r');

        $this->assertSame(['/r/a.php', '/r/b.php'], $files);
    }

    public function testReadUnifiedDiffPullsPostImagePathsAndIgnoresDeletions(): void
    {
        $diff = <<<DIFF
        diff --git a/app/Foo.php b/app/Foo.php
        index 1111..2222 100644
        --- a/app/Foo.php
        +++ b/app/Foo.php
        @@ -1 +1 @@
        -old
        +new
        diff --git a/app/Removed.php b/dev/null
        deleted file mode 100644
        --- a/app/Removed.php
        +++ /dev/null
        @@ -1 +0,0 @@
        -gone
        diff --git a/app/Renamed.php b/app/RenamedTo.php
        similarity index 100%
        rename from app/Renamed.php
        rename to app/RenamedTo.php
        --- a/app/Renamed.php
        +++ b/app/RenamedTo.php
        DIFF;

        $files = ChangedFilesReader::readUnifiedDiff(self::stream($diff), '/abs/root');

        $this->assertSame(
            ['/abs/root/app/Foo.php', '/abs/root/app/RenamedTo.php'],
            $files,
        );
    }

    public function testReadUnifiedDiffHandlesNoPrefix(): void
    {
        $diff = <<<DIFF
        --- app/Foo.php
        +++ app/Foo.php
        @@ -1 +1 @@
        -x
        +y
        DIFF;

        $files = ChangedFilesReader::readUnifiedDiff(self::stream($diff), '/r');

        $this->assertSame(['/r/app/Foo.php'], $files);
    }

    public function testReadUnifiedDiffStripsTrailingTimestamp(): void
    {
        $diff = "+++ b/app/Foo.php\t2026-01-01 12:00:00.000000000 +0000\n";

        $files = ChangedFilesReader::readUnifiedDiff(self::stream($diff), '/r');

        $this->assertSame(['/r/app/Foo.php'], $files);
    }

    public function testReadFromFlagSplitsCommaAndDedupes(): void
    {
        $files = ChangedFilesReader::readFromFlag(
            ['app/A.php,app/B.php', 'app/A.php', '/abs/C.php'],
            '/r',
        );

        $this->assertSame(
            ['/r/app/A.php', '/r/app/B.php', '/abs/C.php'],
            $files,
        );
    }

    public function testEmptyStreamYieldsEmptyList(): void
    {
        $files = ChangedFilesReader::readNameOnly(self::stream(''), '/r');

        $this->assertSame([], $files);
    }

    public function testEmptyUnifiedDiffYieldsEmptyList(): void
    {
        $files = ChangedFilesReader::readUnifiedDiff(self::stream(''), '/r');

        $this->assertSame([], $files);
    }

    /** @return resource */
    private static function stream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('failed to open in-memory stream');
        }
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
