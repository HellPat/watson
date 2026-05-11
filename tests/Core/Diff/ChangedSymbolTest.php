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
