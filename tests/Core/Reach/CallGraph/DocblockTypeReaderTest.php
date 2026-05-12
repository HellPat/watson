<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Reach\CallGraph;

use PHPUnit\Framework\TestCase;
use Watson\Core\Reach\CallGraph\DocblockTypeReader;

final class DocblockTypeReaderTest extends TestCase
{
    private DocblockTypeReader $reader;

    protected function setUp(): void
    {
        $this->reader = new DocblockTypeReader();
    }

    public function testAbsoluteVarTagResolvesUnchanged(): void
    {
        $doc = "/** @var \\App\\Service\\Foo */";
        self::assertSame('App\\Service\\Foo', $this->reader->forProperty($doc, 'App\\Other', []));
    }

    public function testRelativeVarTagPrependsNamespace(): void
    {
        $doc = "/** @var Foo */";
        self::assertSame('App\\Service\\Foo', $this->reader->forProperty($doc, 'App\\Service', []));
    }

    public function testAliasedVarTagUsesUseMap(): void
    {
        $doc = "/** @var Foo */";
        self::assertSame('App\\Other\\Foo', $this->reader->forProperty($doc, 'App\\Service', ['Foo' => 'App\\Other\\Foo']));
    }

    public function testNullableVarTagUnwraps(): void
    {
        $doc = "/** @var ?Foo */";
        self::assertSame('App\\Service\\Foo', $this->reader->forProperty($doc, 'App\\Service', []));
    }

    public function testGenericVarTagReturnsBase(): void
    {
        $doc = "/** @var Collection<Foo> */";
        self::assertSame('App\\Service\\Collection', $this->reader->forProperty($doc, 'App\\Service', []));
    }

    public function testUnionVarTagReturnsNull(): void
    {
        $doc = "/** @var Foo|Bar */";
        self::assertNull($this->reader->forProperty($doc, 'App\\Service', []));
    }

    public function testScalarVarTagReturnsNull(): void
    {
        $doc = "/** @var int */";
        self::assertNull($this->reader->forProperty($doc, 'App\\Service', []));
    }

    public function testParamTagResolvesByName(): void
    {
        $doc = "/** @param Foo \$svc */";
        self::assertSame('App\\Service\\Foo', $this->reader->forParam($doc, 'svc', 'App\\Service', []));
    }

    public function testParamTagSkipsOtherParams(): void
    {
        $doc = "/** @param Bar \$other\n * @param Foo \$svc */";
        self::assertSame('App\\Service\\Foo', $this->reader->forParam($doc, 'svc', 'App\\Service', []));
    }

    public function testReturnTagResolves(): void
    {
        $doc = "/** @return Foo */";
        self::assertSame('App\\Service\\Foo', $this->reader->forReturn($doc, 'App\\Service', []));
    }

    public function testNullDocCommentReturnsNull(): void
    {
        self::assertNull($this->reader->forProperty(null, 'App', []));
        self::assertNull($this->reader->forParam(null, 'x', 'App', []));
        self::assertNull($this->reader->forReturn(null, 'App', []));
    }

    public function testMalformedDocCommentReturnsNull(): void
    {
        self::assertNull($this->reader->forProperty('/** @var */', 'App', []));
    }
}
