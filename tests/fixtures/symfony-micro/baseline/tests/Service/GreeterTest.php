<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Greeter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class GreeterTest extends TestCase
{
    public function testFormatPrependsHello(): void
    {
        $g = new Greeter();
        $this->assertSame('Hello, world!', $g->format('world'));
    }

    #[Test]
    public function it_handles_empty_input(): void
    {
        $g = new Greeter();
        $this->assertNotEmpty($g->format(''));
    }

    public function setUp(): void
    {
        // not an entry point — does not start with `test`.
    }
}
