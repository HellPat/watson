<?php

declare(strict_types=1);

namespace Watson\Core\Output;

/**
 * One renderer to dispatch by `--format`. JSON is the contract for
 * downstream tooling; markdown is tuned for PR descriptions and AI
 * reviewers; text is the human-readable terminal output. The shared
 * `Envelope` shape means new analyses get the JSON-array fallback for
 * free; per-analysis sections get added here when a renderer wants
 * something richer.
 */
final class Renderer
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_MD = 'md';
    public const FORMAT_TEXT = 'text';

    public static function render(string $format, Envelope $envelope): string
    {
        return match ($format) {
            self::FORMAT_JSON => self::json($envelope),
            self::FORMAT_MD => self::markdown($envelope),
            self::FORMAT_TEXT => self::text($envelope),
            default => throw new \InvalidArgumentException("unknown format: {$format}"),
        };
    }

    private static function json(Envelope $envelope): string
    {
        return json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function markdown(Envelope $envelope): string
    {
        $serialised = json_decode(json_encode($envelope), true);
        $lines = [];
        $lines[] = sprintf('# watson — %s %s', $serialised['language'], $serialised['framework']);
        $lines[] = '';
        $lines[] = sprintf('_tool %s v%s_', $serialised['tool'], $serialised['version']);
        $lines[] = '';
        $base = $serialised['context']['base'] ?? null;
        $head = $serialised['context']['head'] ?? null;
        if ($base !== null && $head !== null) {
            $lines[] = sprintf('Diff: `%s` → `%s`', self::short($base), self::short($head));
        }
        $lines[] = sprintf('Root: `%s`', $serialised['context']['root']);
        $lines[] = '';

        foreach ($serialised['analyses'] as $analysis) {
            $lines[] = sprintf('## %s', $analysis['name']);
            $lines[] = sprintf('_v%s_', $analysis['version']);
            $lines[] = '';
            if (!($analysis['ok'] ?? false)) {
                $lines[] = '**Error**: ' . ($analysis['error']['message'] ?? '(unknown)');
                $lines[] = '';
                continue;
            }
            self::renderAnalysis($analysis['name'], $analysis['result'], $lines);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private static function text(Envelope $envelope): string
    {
        $serialised = json_decode(json_encode($envelope), true);
        $header = sprintf('watson %s %s (root: %s)', $serialised['language'], $serialised['framework'], $serialised['context']['root']);
        $bar = str_repeat('=', min(80, strlen($header)));
        $lines = [$bar, $header];
        $base = $serialised['context']['base'] ?? null;
        $head = $serialised['context']['head'] ?? null;
        if ($base !== null && $head !== null) {
            $lines[] = sprintf('diff: %s -> %s', self::short($base), self::short($head));
        }
        $lines[] = $bar;
        $lines[] = '';

        foreach ($serialised['analyses'] as $analysis) {
            $tag = ($analysis['ok'] ?? false) ? '' : ' (FAILED)';
            $lines[] = sprintf('[%s]%s', $analysis['name'], $tag);
            $lines[] = '';
            if (!($analysis['ok'] ?? false)) {
                $lines[] = '  error: ' . ($analysis['error']['message'] ?? '(unknown)');
                $lines[] = '';
                continue;
            }
            self::renderAnalysisText($analysis['name'], $analysis['result'], $lines);
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<array<string,mixed>> $lines */
    private static function renderAnalysis(string $name, array $result, array &$lines): void
    {
        if ($name === 'list-entrypoints') {
            $eps = $result['entry_points'] ?? [];
            $lines[] = sprintf('**%d entry point%s**', count($eps), count($eps) === 1 ? '' : 's');
            $lines[] = '';
            if ($eps === []) {
                $lines[] = '_None detected._';

                return;
            }
            $lines[] = '| kind | name | handler |';
            $lines[] = '|---|---|---|';
            foreach ($eps as $ep) {
                $loc = ($ep['handler_path'] ?? '') === ''
                    ? sprintf('`%s`', $ep['handler_fqn'] ?? '?')
                    : sprintf('`%s` (`%s:%d`)', $ep['handler_fqn'] ?? '?', $ep['handler_path'] ?? '?', $ep['handler_line'] ?? 0);
                $lines[] = sprintf('| `%s` | `%s` | %s |', $ep['kind'] ?? '?', $ep['name'] ?? '?', $loc);
            }

            return;
        }

        if ($name === 'blastradius') {
            $summary = $result['summary'] ?? [];
            $lines[] = sprintf(
                '**Summary** — %d files changed · %d entry points affected',
                $summary['files_changed'] ?? 0,
                $summary['entry_points_affected'] ?? 0,
            );
            $lines[] = '';
            $affected = $result['affected_entry_points'] ?? [];
            if ($affected === []) {
                $lines[] = '### Affected entry points';
                $lines[] = '';
                $lines[] = '_None._ The diff did not transitively reach any HTTP route, console command, message handler, or scheduled task.';

                return;
            }
            $lines[] = sprintf('### Affected entry points (%d)', count($affected));
            $lines[] = '';
            foreach (self::groupByKind($affected) as $kind => $entries) {
                $lines[] = sprintf('#### %s (%d)', $kind, count($entries));
                $lines[] = '';
                foreach ($entries as $ep) {
                    $lines[] = sprintf('##### %s', $ep['name'] ?? '?');
                    $lines[] = '';
                    $lines[] = sprintf(
                        '- **Handler**: `%s` (`%s:%d`)',
                        $ep['handler']['fqn'] ?? '?',
                        $ep['handler']['path'] ?? '?',
                        $ep['handler']['line'] ?? 0,
                    );
                    if (isset($ep['extra']['path'])) {
                        $methods = isset($ep['extra']['methods']) && is_array($ep['extra']['methods'])
                            ? implode(', ', $ep['extra']['methods'])
                            : '';
                        if ($methods !== '') {
                            $lines[] = sprintf('- **HTTP**: %s `%s`', $methods, $ep['extra']['path']);
                        } else {
                            $lines[] = sprintf('- **HTTP path**: `%s`', $ep['extra']['path']);
                        }
                    }
                    $lines[] = '';
                }
            }
        }
    }

    /** @param list<array<string,mixed>> $lines */
    private static function renderAnalysisText(string $name, array $result, array &$lines): void
    {
        if ($name === 'list-entrypoints') {
            $eps = $result['entry_points'] ?? [];
            $lines[] = sprintf('  %d entry point(s):', count($eps));
            foreach ($eps as $ep) {
                $lines[] = sprintf(
                    '    - %-24s %-30s %s',
                    $ep['kind'] ?? '?',
                    $ep['name'] ?? '?',
                    $ep['handler_fqn'] ?? '?',
                );
            }

            return;
        }

        if ($name === 'blastradius') {
            $summary = $result['summary'] ?? [];
            $lines[] = sprintf(
                '  summary: %d files, %d entry points affected',
                $summary['files_changed'] ?? 0,
                $summary['entry_points_affected'] ?? 0,
            );
            $affected = $result['affected_entry_points'] ?? [];
            if ($affected === []) {
                $lines[] = '  no entry points affected';

                return;
            }
            foreach (self::groupByKind($affected) as $kind => $entries) {
                $lines[] = sprintf('  %s (%d):', $kind, count($entries));
                foreach ($entries as $ep) {
                    $lines[] = sprintf('    - %s', $ep['name'] ?? '?');
                    $lines[] = sprintf(
                        '        handler: %s (%s:%d)',
                        $ep['handler']['fqn'] ?? '?',
                        $ep['handler']['path'] ?? '?',
                        $ep['handler']['line'] ?? 0,
                    );
                }
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return array<string, list<array<string,mixed>>>
     */
    private static function groupByKind(array $entries): array
    {
        $order = [
            'symfony.route',
            'symfony.command',
            'symfony.message_handler',
            'symfony.event_listener',
            'symfony.cron_task',
            'symfony.periodic_task',
            'symfony.schedule_provider',
            'laravel.route',
            'laravel.command',
            'laravel.job',
            'laravel.listener',
            'laravel.scheduled_task',
            'phpunit.test',
        ];
        $buckets = [];
        foreach ($entries as $ep) {
            $kind = $ep['kind'] ?? '?';
            $buckets[$kind][] = $ep;
        }
        foreach ($buckets as &$list) {
            usort($list, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
        }
        unset($list);

        $sorted = [];
        foreach ($order as $kind) {
            if (isset($buckets[$kind])) {
                $sorted[$kind] = $buckets[$kind];
                unset($buckets[$kind]);
            }
        }
        ksort($buckets);

        return $sorted + $buckets;
    }

    private static function short(string $sha): string
    {
        if (strlen($sha) > 12 && ctype_xdigit($sha)) {
            return substr($sha, 0, 12);
        }

        return $sha;
    }
}
