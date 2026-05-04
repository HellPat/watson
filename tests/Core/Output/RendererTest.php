<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Output;

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

        $this->assertStringContainsString('`symfony.route`', $out);
        $this->assertStringContainsString('`symfony.command`', $out);
        // Symfony.route comes before symfony.command in the stable order.
        $routePos = strpos($out, '`symfony.route`');
        $commandPos = strpos($out, '`symfony.command`');
        $this->assertNotFalse($routePos);
        $this->assertNotFalse($commandPos);
        $this->assertLessThan($commandPos, $routePos);
    }

    public function testMarkdownRendersFencedSymfonyTableWithIconsAndReachBadge(): void
    {
        $envelope = self::sampleBlastradiusEnvelope();
        $envelope = self::withConfidence($envelope, ['NameOnly', 'Transitive']);
        $out = Renderer::render(Renderer::FORMAT_MD, $envelope);

        // Each kind block is wrapped in a fenced code block.
        $this->assertStringContainsString("```text\n", $out);
        // Symfony Table column headers (from box-style render).
        $this->assertStringContainsString('reach', $out);
        $this->assertStringContainsString('name', $out);
        $this->assertStringContainsString('handler', $out);
        // UTF-8 box-drawing characters from our table style.
        $this->assertStringContainsString('┌', $out);
        $this->assertStringContainsString('│', $out);
        $this->assertStringContainsString('└', $out);
        // Kind icons next to the kind label.
        $this->assertStringContainsString('🛣️', $out);
        $this->assertStringContainsString('⌨️', $out);
        // Reach badges (no backticks inside table cells).
        $this->assertStringContainsString('🎯 direct', $out);
        $this->assertStringContainsString('🔗 transitive', $out);
        // Handler FQN + path:line on a single line inside the cell.
        $this->assertStringContainsString('App\\HomeController::index (src/HomeController.php:8)', $out);
    }

    public function testTextRendersSummaryAndCounts(): void
    {
        $envelope = self::sampleBlastradiusEnvelope();
        $out = Renderer::render(Renderer::FORMAT_TEXT, $envelope);

        $this->assertStringContainsString('summary:', $out);
        $this->assertStringContainsString('symfony.route', $out);
        $this->assertStringContainsString('symfony.command', $out);
    }

    public function testTokIsTabSeparatedAndHasKindLegend(): void
    {
        $envelope = self::sampleListEnvelope();
        $out = Renderer::render(Renderer::FORMAT_TOK, $envelope);

        $this->assertStringContainsString('# watson', $out);
        $this->assertStringContainsString('# entrypoints=1', $out);
        $this->assertStringContainsString('# kinds: sr=symfony.route', $out);
        // Body row: kind \t name \t fqn \t path:line \t extra
        $this->assertMatchesRegularExpression('/\nsr\tname\t.*\t.*\t/', "\n" . $out);
        // Crude but clear: tok output should be markedly shorter than json.
        $json = Renderer::render(Renderer::FORMAT_JSON, $envelope);
        $this->assertLessThan(strlen($json), strlen($out));
    }

    public function testTokBlastradiusIncludesSummaryHeader(): void
    {
        $envelope = self::sampleBlastradiusEnvelope();
        $out = Renderer::render(Renderer::FORMAT_TOK, $envelope);

        $this->assertStringContainsString('# files=2 affected=2', $out);
        $this->assertStringContainsString("\nsr\thome\t", $out);
        $this->assertStringContainsString("\nsc\tapp:ping\t", $out);
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
                ['kind' => 'symfony.route', 'name' => 'name', 'handler_fqn' => 'X::y', 'handler_path' => '/abs/X.php', 'handler_line' => 12, 'source' => 'runtime', 'extra' => ['path' => '/', 'methods' => ['GET']]],
            ],
        ]);

        return $envelope;
    }

    /**
     * Re-emit the same blastradius analysis with explicit min_confidence
     * values per affected entry point (in stable kind order — command
     * first, then route, matching the fixture).
     *
     * @param list<string> $confidences
     */
    private static function withConfidence(Envelope $envelope, array $confidences): Envelope
    {
        $analyses = $envelope->jsonSerialize()['analyses'] ?? [];
        $rebuilt = new Envelope(language: 'php', framework: 'symfony', rootPath: '/x', base: 'main', head: 'HEAD');
        foreach ($analyses as $a) {
            if (($a['name'] ?? '') === 'blastradius') {
                $eps = $a['result']['affected_entry_points'] ?? [];
                foreach ($eps as $i => &$ep) {
                    $ep['min_confidence'] = $confidences[$i] ?? 'NameOnly';
                }
                unset($ep);
                $a['result']['affected_entry_points'] = $eps;
            }
            $rebuilt->pushAnalysis($a['name'], $a['version'], $a['result'] ?? []);
        }
        return $rebuilt;
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
