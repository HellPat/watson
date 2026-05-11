<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Diff;

use PHPUnit\Framework\TestCase;
use Watson\Core\Diff\ChangedSymbol;

final class ChangedSymbolTest extends TestCase
{
    public function testSymbolForClassMethod(): void
    {
        $cs = new ChangedSymbol('/abs/Foo.php', 'App\\Foo', 'bar', 10, 20);
        self::assertSame('App\\Foo::bar', $cs->symbol());
    }

    public function testSymbolForClassWildcard(): void
    {
        $cs = new ChangedSymbol('/abs/Foo.php', 'App\\Foo', null, 1, 50);
        self::assertSame('App\\Foo::*', $cs->symbol());
    }

    public function testSymbolForFileLevel(): void
    {
        $cs = new ChangedSymbol('/abs/dir/file.php', null, null, 1, 80);
        self::assertSame('file.php', $cs->symbol());
    }

    public function testWithRelativeFileStripsProjectRoot(): void
    {
        $tmp = sys_get_temp_dir() . '/watson_cs_' . uniqid();
        mkdir($tmp . '/app/Service', 0700, true);
        file_put_contents($tmp . '/app/Service/Foo.php', '<?php class Foo {}');
        try {
            $cs = new ChangedSymbol($tmp . '/app/Service/Foo.php', 'App\\Service\\Foo', 'bar', 10, 20);
            $rel = $cs->withRelativeFile($tmp);
            self::assertSame('app/Service/Foo.php', $rel->filePath);
            // Other fields unchanged.
            self::assertSame('App\\Service\\Foo', $rel->classFqn);
            self::assertSame('bar', $rel->methodName);
            self::assertSame(10, $rel->startLine);
            self::assertSame(20, $rel->endLine);
        } finally {
            @unlink($tmp . '/app/Service/Foo.php');
            @rmdir($tmp . '/app/Service');
            @rmdir($tmp . '/app');
            @rmdir($tmp);
        }
    }

    public function testWithRelativeFileLeavesOutsidePathsUntouched(): void
    {
        $cs = new ChangedSymbol('/abs/elsewhere/Foo.php', 'App\\Foo', null, 1, 1);
        $rel = $cs->withRelativeFile('/different/root');
        self::assertSame('/abs/elsewhere/Foo.php', $rel->filePath);
    }

    public function testJsonSerializableEmitsSymbol(): void
    {
        $cs = new ChangedSymbol('/abs/Foo.php', 'App\\Foo', 'bar', 10, 20);
        $encoded = json_encode($cs);
        self::assertIsString($encoded);
        $decoded = json_decode($encoded, true);
        self::assertSame('App\\Foo::bar', $decoded['symbol']);
        self::assertSame('App\\Foo', $decoded['class']);
        self::assertSame('bar', $decoded['method']);
        self::assertSame(10, $decoded['start_line']);
        self::assertSame(20, $decoded['end_line']);
    }
}
