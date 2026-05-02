<?php

declare(strict_types=1);

namespace Watson\Core\Tests\Output;

use PHPUnit\Framework\TestCase;
use Watson\Core\Output\Envelope;
use Watson\Core\Output\Renderer;

final class RendererTest extends TestCase
{
    public function testJsonOutputIsValid(): void
    {
        $envelope = self::sampleListEnvelope();
        $out = Renderer::render(Renderer::FORMAT_JSON, $envelope);

        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded);
        $this->assertSame('watson', $decoded['tool']);
    }

    public function testMarkdownGroupsAffectedEntryPointsByKind(): void
    {
        $envelope = self::sampleBlastradiusEnvelope();
        $out = Renderer::render(Renderer::FORMAT_MD, $envelope);

        $this->assertStringContainsString('#### symfony.route', $out);
        $this->assertStringContainsString('#### symfony.command', $out);
        // Symfony.route comes before symfony.command in the stable order.
        $routePos = strpos($out, '#### symfony.route');
        $commandPos = strpos($out, '#### symfony.command');
        $this->assertNotFalse($routePos);
        $this->assertNotFalse($commandPos);
        $this->assertLessThan($commandPos, $routePos);
    }

    public function testTextRendersSummaryAndCounts(): void
    {
        $envelope = self::sampleBlastradiusEnvelope();
        $out = Renderer::render(Renderer::FORMAT_TEXT, $envelope);

        $this->assertStringContainsString('summary:', $out);
        $this->assertStringContainsString('symfony.route', $out);
        $this->assertStringContainsString('symfony.command', $out);
    }

    public function testUnknownFormatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Renderer::render('xml', self::sampleListEnvelope());
    }

    private static function sampleListEnvelope(): Envelope
    {
        $envelope = new Envelope(language: 'php', framework: 'symfony', rootPath: '/x');
        $envelope->pushAnalysis('list-entrypoints', '0.2.0', [
            'entry_points' => [
                ['kind' => 'symfony.route', 'name' => 'home', 'handler_fqn' => 'X::y', 'handler_path' => '/abs/X.php', 'handler_line' => 12, 'source' => 'runtime'],
            ],
        ]);

        return $envelope;
    }

    private static function sampleBlastradiusEnvelope(): Envelope
    {
        $envelope = new Envelope(language: 'php', framework: 'symfony', rootPath: '/x', base: 'main', head: 'HEAD');
        $envelope->pushAnalysis('blastradius', '0.2.0', [
            'summary' => ['files_changed' => 2, 'entry_points_affected' => 2],
            'affected_entry_points' => [
                [
                    'kind' => 'symfony.command',
                    'name' => 'app:ping',
                    'handler' => ['fqn' => 'App\\PingCommand::execute', 'path' => 'src/PingCommand.php', 'line' => 10],
                    'extra' => null,
                ],
                [
                    'kind' => 'symfony.route',
                    'name' => 'home',
                    'handler' => ['fqn' => 'App\\HomeController::index', 'path' => 'src/HomeController.php', 'line' => 8],
                    'extra' => ['path' => '/', 'methods' => ['GET']],
                ],
            ],
        ]);

        return $envelope;
    }
}
