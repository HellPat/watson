<?php

declare(strict_types=1);

namespace Watson\Core\Output;

/**
 * One renderer to dispatch by `--format`. JSON is the contract for
 * downstream tooling; markdown is tuned for PR descriptions and AI
 * reviewers; text is the human-readable terminal output; tok is a
 * tab-separated token-optimized format for piping into LLMs without
 * paying for JSON whitespace and key noise.
 */
final class Renderer
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_MD = 'md';
    public const FORMAT_TEXT = 'text';
    public const FORMAT_TOK = 'tok';

    public static function render(string $format, Envelope $envelope): string
    {
        return match ($format) {
            self::FORMAT_JSON => self::json($envelope),
            self::FORMAT_MD => self::markdown($envelope),
            self::FORMAT_TEXT => self::text($envelope),
            self::FORMAT_TOK => self::tok($envelope),
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

    /**
     * Token-optimized format: one row per entry point, tab-separated, no
     * JSON keys, no whitespace padding. Designed for piping into LLM
     * prompts where every token costs money. Header lines start with `#`
     * so the LLM (or `awk`) can split header from body trivially.
     *
     *   # watson <ver> <analysis> <lang>/<framework> root=<root> [base=…] [head=…]
     *   # entrypoints=N           (or files=N affected=N for blastradius)
     *   # kinds: <code>=<full> …  (legend, only kinds present in body)
     *   # fields: kind name handler path:line extra
     *   <kind>\t<name>\t<handler_fqn>\t<rel/path:line>\t<extra>
     */
    private static function tok(Envelope $envelope): string
    {
        $serialised = json_decode(json_encode($envelope), true);
        $tool = (string) ($serialised['tool'] ?? 'watson');
        $version = (string) ($serialised['version'] ?? '');
        $lang = (string) ($serialised['language'] ?? '');
        $framework = (string) ($serialised['framework'] ?? '');
        $root = (string) ($serialised['context']['root'] ?? '');
        $baseRev = $serialised['context']['base'] ?? null;
        $headRev = $serialised['context']['head'] ?? null;

        $lines = [];
        foreach ($serialised['analyses'] as $analysis) {
            $name = (string) ($analysis['name'] ?? '?');
            $head = sprintf('# %s %s %s %s/%s root=%s', $tool, $version, $name, $lang, $framework, $root);
            if ($baseRev !== null && $headRev !== null) {
                $head .= sprintf(' base=%s head=%s', self::short((string) $baseRev), self::short((string) $headRev));
            }
            $lines[] = $head;

            if (!($analysis['ok'] ?? false)) {
                $lines[] = '# error: ' . ($analysis['error']['message'] ?? '(unknown)');

                continue;
            }

            $rows = match ($name) {
                'list-entrypoints' => self::tokListRows($analysis['result'] ?? [], $root, $lines),
                'blastradius' => self::tokBlastRows($analysis['result'] ?? [], $root, $lines),
                default => [],
            };
            foreach ($rows as $row) {
                $lines[] = $row;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,mixed> $result
     * @param list<string>        $headerLines
     * @return list<string>
     */
    private static function tokListRows(array $result, string $root, array &$headerLines): array
    {
        $entryPoints = is_array($result['entry_points'] ?? null) ? $result['entry_points'] : [];
        $rows = [];
        $kinds = [];
        foreach ($entryPoints as $entryPoint) {
            $kind = (string) ($entryPoint['kind'] ?? '?');
            $kinds[$kind] = true;
            $rows[] = self::tokRow(
                self::shortKind($kind),
                (string) ($entryPoint['name'] ?? '?'),
                (string) ($entryPoint['handler_fqn'] ?? ''),
                (string) ($entryPoint['handler_path'] ?? ''),
                (int) ($entryPoint['handler_line'] ?? 0),
                is_array($entryPoint['extra'] ?? null) ? $entryPoint['extra'] : null,
                $root,
            );
        }
        $headerLines[] = sprintf('# entrypoints=%d', count($entryPoints));
        $headerLines[] = self::tokKindLegend(array_keys($kinds));
        $headerLines[] = '# fields: kind\tname\thandler\tpath:line\textra';

        return $rows;
    }

    /**
     * @param array<string,mixed> $result
     * @param list<string>        $headerLines
     * @return list<string>
     */
    private static function tokBlastRows(array $result, string $root, array &$headerLines): array
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $headerLines[] = sprintf(
            '# files=%d affected=%d',
            (int) ($summary['files_changed'] ?? 0),
            (int) ($summary['entry_points_affected'] ?? 0),
        );
        $affected = is_array($result['affected_entry_points'] ?? null) ? $result['affected_entry_points'] : [];
        if ($affected === []) {
            $headerLines[] = '# no entry points affected';

            return [];
        }
        $rows = [];
        $kinds = [];
        foreach ($affected as $entryPoint) {
            $kind = (string) ($entryPoint['kind'] ?? '?');
            $kinds[$kind] = true;
            $handler = is_array($entryPoint['handler'] ?? null) ? $entryPoint['handler'] : [];
            $rows[] = self::tokRow(
                self::shortKind($kind),
                (string) ($entryPoint['name'] ?? '?'),
                (string) ($handler['fqn'] ?? ''),
                (string) ($handler['path'] ?? ''),
                (int) ($handler['line'] ?? 0),
                is_array($entryPoint['extra'] ?? null) ? $entryPoint['extra'] : null,
                $root,
            );
        }
        $headerLines[] = self::tokKindLegend(array_keys($kinds));
        $headerLines[] = '# fields: kind\tname\thandler\tpath:line\textra';

        return $rows;
    }

    /**
     * @param array<string,mixed>|null $extra
     */
    private static function tokRow(
        string $kind,
        string $name,
        string $fqn,
        string $path,
        int $line,
        ?array $extra,
        string $root,
    ): string {
        $rel = self::relPath($path, $root);
        $loc = $rel === '' ? '' : ($line > 0 ? $rel . ':' . $line : $rel);

        $extraStr = '';
        if (is_array($extra) && isset($extra['path'])) {
            $methods = is_array($extra['methods'] ?? null) ? implode(',', $extra['methods']) : '';
            $extraStr = trim($methods . ' ' . (string) $extra['path']);
        }

        return $kind . "\t" . $name . "\t" . $fqn . "\t" . $loc . "\t" . $extraStr;
    }

    private static function shortKind(string $kind): string
    {
        return match ($kind) {
            'laravel.route' => 'lr',
            'laravel.command' => 'lc',
            'laravel.job' => 'lj',
            'laravel.listener' => 'll',
            'laravel.scheduled_task' => 'ls',
            'symfony.route' => 'sr',
            'symfony.command' => 'sc',
            'symfony.message_handler' => 'smh',
            'symfony.event_listener' => 'sel',
            'symfony.cron_task' => 'sct',
            'symfony.periodic_task' => 'spt',
            'symfony.schedule_provider' => 'ssp',
            'phpunit.test' => 'pt',
            default => $kind,
        };
    }

    /** @param list<string> $kinds full kind labels present in the body */
    private static function tokKindLegend(array $kinds): string
    {
        if ($kinds === []) {
            return '# kinds:';
        }
        sort($kinds);
        $entries = [];
        foreach ($kinds as $full) {
            $entries[] = self::shortKind($full) . '=' . $full;
        }

        return '# kinds: ' . implode(' ', $entries);
    }

    private static function relPath(string $path, string $root): string
    {
        if ($path === '') {
            return '';
        }
        $real = realpath($path);
        $rootReal = realpath($root) ?: $root;
        $candidate = $real !== false ? $real : $path;
        if (str_starts_with($candidate, $rootReal . DIRECTORY_SEPARATOR)) {
            return substr($candidate, strlen($rootReal) + 1);
        }

        return $path;
    }

    /** @param list<array<string,mixed>> $lines */
    private static function renderAnalysis(string $name, array $result, array &$lines): void
    {
        if ($name === 'list-entrypoints') {
            $entryPoints = $result['entry_points'] ?? [];
            $lines[] = sprintf('**%d entry point%s**', count($entryPoints), count($entryPoints) === 1 ? '' : 's');
            $lines[] = '';
            if ($entryPoints === []) {
                $lines[] = '_None detected._';

                return;
            }
            foreach (self::groupByKind($entryPoints) as $kind => $entries) {
                $rows = [];
                foreach ($entries as $entryPoint) {
                    $rows[] = [
                        self::formatEntryPointCell(
                            self::formatName($entryPoint['extra'] ?? null, $entryPoint['name'] ?? '?'),
                            $entryPoint['handler_fqn'] ?? '?',
                            $entryPoint['handler_path'] ?? '',
                            (int) ($entryPoint['handler_line'] ?? 0),
                            null,
                        ),
                    ];
                }
                $lines[] = sprintf('#### %s `%s` — %d', self::kindIcon($kind), $kind, count($entries));
                $lines[] = '';
                self::appendGfmTable($lines, ['entry point'], $rows);
                $lines[] = '';
            }

            return;
        }

        if ($name === 'blastradius') {
            $affected = $result['affected_entry_points'] ?? [];
            if ($affected === []) {
                $lines[] = '_💤 nothing reached. Diff did not touch any registered entry point or its direct callers._';

                return;
            }
            $lines[] = self::reachLegend();
            $lines[] = '';
            foreach (self::groupByKind($affected) as $kind => $entries) {
                $rows = [];
                foreach ($entries as $entryPoint) {
                    /** @var list<string>|null $reachPath */
                    $reachPath = isset($entryPoint['reach_path']) && is_array($entryPoint['reach_path']) ? array_values($entryPoint['reach_path']) : null;
                    /** @var list<array{symbol:string,file?:string,class:?string,method:?string}> $triggers */
                    $triggers = isset($entryPoint['triggered_by']) && is_array($entryPoint['triggered_by']) ? $entryPoint['triggered_by'] : [];
                    $rows[] = [
                        self::reachBadge($entryPoint['min_confidence'] ?? null),
                        self::formatTriggers($triggers),
                        self::formatEntryPointCell(
                            self::formatName($entryPoint['extra'] ?? null, $entryPoint['name'] ?? '?'),
                            $entryPoint['handler']['fqn'] ?? '?',
                            $entryPoint['handler']['path'] ?? '',
                            (int) ($entryPoint['handler']['line'] ?? 0),
                            $reachPath,
                        ),
                    ];
                }
                $lines[] = sprintf('#### %s `%s` — %d', self::kindIcon($kind), $kind, count($entries));
                $lines[] = '';
                self::appendGfmTable($lines, ['reach', 'affected by changed', 'entry point'], $rows);
                $lines[] = '';
            }
        }
    }

    /**
     * Append a GitHub-flavoured-markdown table. GFM doesn't allow literal
     * `|` or newlines inside cells, so callers MUST encode line breaks as
     * `<br>` and pre-escape pipes — `formatEntryPointCell` does both.
     *
     * @param-out list<string>      $lines
     * @param list<string>          $headers
     * @param list<list<string>>    $rows
     */
    private static function appendGfmTable(array &$lines, array $headers, array $rows): void
    {
        $lines[] = '| ' . implode(' | ', $headers) . ' |';
        $lines[] = '|' . str_repeat('---|', count($headers));
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = self::escapeGfmCell($cell);
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }
    }

    private static function escapeGfmCell(string $cell): string
    {
        // GFM table cells can't contain literal pipes or newlines. Pipes
        // inside `<details>` (e.g. inside route names like `GET|POST /x`)
        // need to be escaped so the table parser doesn't see a column break.
        // Newlines are allowed only as `<br>`.
        $cell = str_replace(["\r\n", "\n", "\r"], '<br>', $cell);
        return str_replace('|', '\\|', $cell);
    }

    private static function reachLegend(): string
    {
        return '<sub>'
            . '🎯 `direct` — entry point\'s own handler file is in the diff'
            . ' &nbsp;·&nbsp; '
            . '🔗 `indirect` — handler reaches a changed file through its imports / `new` / static calls / type hints'
            . '</sub>';
    }

    /**
     * Render the trigger cell ("affected by changed"). Symbol-only, one
     * per line. Falls back to "—" when no trigger was attributed (should
     * be rare; happens for file-level changes when running in
     * `--name-only` mode).
     *
     * @param list<array{symbol?:string,class?:?string,method?:?string}> $triggers
     */
    private static function formatTriggers(array $triggers): string
    {
        $symbols = array_unique(array_filter(array_column($triggers, 'symbol')));
        if ($symbols === []) {
            return '—';
        }
        return implode('<br>', array_map(static fn (string $s): string => '<code>' . $s . '</code>', $symbols));
    }

    private static function kindIcon(string $kind): string
    {
        return match ($kind) {
            'laravel.route', 'symfony.route'                                            => '🛣️',
            'laravel.command', 'symfony.command'                                        => '⌨️',
            'laravel.job'                                                               => '⚡',
            'laravel.listener', 'symfony.event_listener'                                => '👂',
            'symfony.message_handler'                                                   => '📨',
            'laravel.scheduled_task', 'symfony.cron_task', 'symfony.periodic_task',
            'symfony.schedule_provider'                                                 => '⏱️',
            'phpunit.test'                                                              => '🧪',
            default                                                                     => '🔹',
        };
    }

    private static function reachBadge(?string $confidence): string
    {
        return match ($confidence) {
            'NameOnly'             => '🎯 direct',
            'Indirect', 'Transitive' => '🔗 indirect',
            default                => '·',
        };
    }

    private static function formatName(?array $extra, string $fallback): string
    {
        if (is_array($extra) && isset($extra['path'])) {
            $methods = isset($extra['methods']) && is_array($extra['methods'])
                ? implode('|', $extra['methods'])
                : '';
            return $methods !== ''
                ? sprintf('%s %s', $methods, $extra['path'])
                : (string) $extra['path'];
        }
        return $fallback;
    }


    /**
     * Render the combined "entry point" cell — name on the first line,
     * handler FQN on the second, file:line on the third. Indirect hits
     * tack on a collapsible `↳ N hops` chain so the row stays scannable
     * at table width but the call chain is one click away.
     *
     * @param list<string>|null $reachPath
     */
    private static function formatEntryPointCell(string $name, string $fqn, string $path, int $line, ?array $reachPath): string
    {
        $lines = [
            '<strong>' . $name . '</strong>',
            '<code>' . $fqn . '</code>',
        ];
        if ($path !== '') {
            $lines[] = '<sub>' . $path . ($line > 0 ? ':' . $line : '') . '</sub>';
        }
        $cell = implode('<br>', $lines);

        if ($reachPath === null || $reachPath === []) {
            return $cell;
        }
        $hops  = count($reachPath);
        $chain = '<code>' . $path . '</code>';
        foreach ($reachPath as $step) {
            $chain .= '<br>↳ <code>' . $step . '</code>';
        }
        return sprintf(
            '%s<br><details><summary>↳ %d hop%s</summary>%s</details>',
            $cell,
            $hops,
            $hops === 1 ? '' : 's',
            $chain,
        );
    }

    /** @param list<array<string,mixed>> $lines */
    private static function renderAnalysisText(string $name, array $result, array &$lines): void
    {
        if ($name === 'list-entrypoints') {
            $entryPoints = $result['entry_points'] ?? [];
            $lines[] = sprintf('  %d entry point(s):', count($entryPoints));
            foreach ($entryPoints as $entryPoint) {
                $lines[] = sprintf(
                    '    - %-24s %-30s %s',
                    $entryPoint['kind'] ?? '?',
                    $entryPoint['name'] ?? '?',
                    $entryPoint['handler_fqn'] ?? '?',
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
                foreach ($entries as $entryPoint) {
                    $lines[] = sprintf('    - %s', $entryPoint['name'] ?? '?');
                    $lines[] = sprintf(
                        '        handler: %s (%s:%d)',
                        $entryPoint['handler']['fqn'] ?? '?',
                        $entryPoint['handler']['path'] ?? '?',
                        $entryPoint['handler']['line'] ?? 0,
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
        foreach ($entries as $entryPoint) {
            $kind = $entryPoint['kind'] ?? '?';
            $buckets[$kind][] = $entryPoint;
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
