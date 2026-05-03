<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Entrypoint;

use PHPUnit\Framework\TestCase;
use Watson\Core\Entrypoint\Source;

final class SourceTest extends TestCase
{
    public function testPriorityOrdersRuntimeHighest(): void
    {
        $this->assertGreaterThan(Source::Attribute->priority(), Source::Runtime->priority());
        $this->assertGreaterThan(Source::Interface_->priority(), Source::Attribute->priority());
        $this->assertGreaterThan(Source::StaticCall->priority(), Source::Interface_->priority());
    }

    public function testWireFormatStringsStable(): void
    {
        // These string values are part of the public JSON contract. Test
        // pins the wire format so a refactor can't quietly rename them.
        $this->assertSame('runtime', Source::Runtime->value);
        $this->assertSame('attribute', Source::Attribute->value);
        $this->assertSame('interface', Source::Interface_->value);
        $this->assertSame('static-call', Source::StaticCall->value);
    }
}
