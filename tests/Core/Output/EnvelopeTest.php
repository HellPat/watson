<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Output;

use PHPUnit\Framework\TestCase;
use Watson\Core\Output\Envelope;

final class EnvelopeTest extends TestCase
{
    public function testJsonShapeMatchesSchema(): void
    {
        $envelope = new Envelope(language: 'php', rootPath: '/x', base: 'main', head: 'HEAD');
        $envelope->pushAnalysis('list-entrypoints', '0.2.0', ['entry_points' => []]);

        $payload = json_decode(json_encode($envelope), true);

        $this->assertSame('watson', $payload['tool']);
        $this->assertSame('php', $payload['language']);
        $this->assertArrayNotHasKey('framework', $payload);
        $this->assertSame([], $payload['sources']);
        $this->assertSame(['root' => '/x', 'base' => 'main', 'head' => 'HEAD'], $payload['context']);
        $this->assertCount(1, $payload['analyses']);
        $this->assertTrue($payload['analyses'][0]['ok']);
        $this->assertSame('list-entrypoints', $payload['analyses'][0]['name']);
    }

    public function testFailedAnalysisDropsResult(): void
    {
        $envelope = new Envelope(language: 'php', rootPath: '/x');
        $envelope->pushFailedAnalysis('blastradius', '0.2.0', 'git failed', 'git-error');

        $payload = json_decode(json_encode($envelope), true);
        $this->assertFalse($payload['analyses'][0]['ok']);
        $this->assertSame('git-error', $payload['analyses'][0]['error']['kind']);
        $this->assertSame('git failed', $payload['analyses'][0]['error']['message']);
        $this->assertArrayNotHasKey('result', $payload['analyses'][0]);
    }

    public function testContextOmitsBaseAndHeadWhenNull(): void
    {
        $envelope = new Envelope(language: 'php', rootPath: '/x');
        $payload = json_decode(json_encode($envelope), true);
        $this->assertSame(['root' => '/x'], $payload['context']);
    }
}
