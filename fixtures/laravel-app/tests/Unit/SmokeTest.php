<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPasses(): void
    {
        $this->assertTrue(true);
    }

    public function testAlsoPasses(): void
    {
        $this->assertSame(1, 1);
    }
}
