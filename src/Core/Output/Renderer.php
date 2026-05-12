<?php

declare(strict_types=1);

namespace Watson\Core\Output;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Format dispatcher for {@see Envelope}. JSON stays a direct
 * `json_encode` of the envelope (machine contract — no template
 * indirection). Every other format is rendered from a `.twig` template
 * under `templates/` via an isolated Twig environment:
 *
 *   - no cache (`cache: false`)
 *   - no autoescape (`autoescape: false` — we control markdown / text /
 *     tab output manually)
 *   - strict variables (`strict_variables: true`)
 *
 * Layout: each format has one top-level envelope template that
 * iterates `analyses[]` and includes a per-analysis partial.
 */
final class Renderer
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_MD = 'md';
    public const FORMAT_TEXT = 'text';
    public const FORMAT_TOK = 'tok';

    private const TEMPLATE_DIR_RELATIVE = '/../../../templates';

    private static ?Environment $twig = null;

    public static function render(string $format, Envelope $envelope): string
    {
        if ($format === self::FORMAT_JSON) {
            return self::json($envelope);
        }
        if (!in_array($format, [self::FORMAT_MD, self::FORMAT_TEXT, self::FORMAT_TOK], true)) {
            throw new \InvalidArgumentException("unknown format: {$format}");
        }
        return self::twig()->render("envelope.{$format}.twig", self::context($envelope));
    }

    private static function json(Envelope $envelope): string
    {
        return json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Build the Twig context once per render. Augments the serialised
     * envelope with per-analysis groupings (so templates iterate, not
     * compute) and per-row sort order (direct before indirect for
     * blastradius).
     *
     * @return array<string, mixed>
     */
    private static function context(Envelope $envelope): array
    {
        $serialised = json_decode(json_encode($envelope), true);
        if (!is_array($serialised)) {
            $serialised = [];
        }
        $analyses = [];
        foreach ($serialised['analyses'] ?? [] as $analysis) {
            $analyses[] = self::prepareAnalysis($analysis);
        }
        $serialised['analyses'] = $analyses;
        return $serialised;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    private static function prepareAnalysis(array $analysis): array
    {
        $name = $analysis['name'] ?? '';
        $result = $analysis['result'] ?? [];

        if ($name === 'list-entrypoints') {
            $result['groups'] = self::groupByKind($result['entry_points'] ?? []);
        } elseif ($name === 'blastradius') {
            $affected = $result['affected_entry_points'] ?? [];
            foreach (self::groupByKind($affected) as $kind => $entries) {
                $result['groups'][$kind] = self::sortDirectFirst($entries);
            }
        }

        $analysis['result'] = $result;
        return $analysis;
    }

    /**
     * Group by `kind` with a stable display order: well-known kinds
     * first in the framework-conventional sequence (routes, commands,
     * message handlers, …), unknown kinds appended alphabetically at
     * the tail. Each bucket is sorted by entry-point name.
     *
     * @param list<array<string,mixed>> $entryPoints
     * @return array<string, list<array<string,mixed>>>
     */
    private static function groupByKind(array $entryPoints): array
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
        foreach ($entryPoints as $entryPoint) {
            $kind = (string) ($entryPoint['kind'] ?? '?');
            $buckets[$kind][] = $entryPoint;
        }
        foreach ($buckets as &$list) {
            usort(
                $list,
                static fn (array $left, array $right): int => strcmp(
                    (string) ($left['name'] ?? ''),
                    (string) ($right['name'] ?? ''),
                ),
            );
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

    /**
     * Stable sort: direct (NameOnly) before indirect; ties on name.
     *
     * @param list<array<string,mixed>> $entries
     * @return list<array<string,mixed>>
     */
    private static function sortDirectFirst(array $entries): array
    {
        usort($entries, static function (array $left, array $right): int {
            $rank = static fn (array $row): int => match ($row['min_confidence'] ?? null) {
                'NameOnly' => 0,
                'Indirect' => 1,
                default    => 2,
            };
            $cmp = $rank($left) <=> $rank($right);
            return $cmp !== 0 ? $cmp : strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });
        return $entries;
    }

    private static function twig(): Environment
    {
        if (self::$twig !== null) {
            return self::$twig;
        }
        $loader = new FilesystemLoader([__DIR__ . self::TEMPLATE_DIR_RELATIVE]);
        $twig = new Environment($loader, [
            'cache'            => false,
            'autoescape'       => false,
            'strict_variables' => true,
        ]);
        self::registerFunctions($twig);
        self::registerFilters($twig);
        return self::$twig = $twig;
    }

    private static function registerFunctions(Environment $twig): void
    {
        $twig->addFunction(new TwigFunction('kind_icon', [self::class, 'kindIcon']));
        $twig->addFunction(new TwigFunction('short_kind', [self::class, 'shortKind']));
        $twig->addFunction(new TwigFunction('reach_badge', [self::class, 'reachBadge']));
        $twig->addFunction(new TwigFunction('status_badge', [self::class, 'statusBadge']));
        $twig->addFunction(new TwigFunction('short_rev', [self::class, 'shortRev']));
        $twig->addFunction(new TwigFunction('format_name', [self::class, 'formatName']));
        $twig->addFunction(new TwigFunction('format_triggers', [self::class, 'formatTriggers']));
        $twig->addFunction(new TwigFunction('format_entry_point_cell', [self::class, 'formatEntryPointCell']));
        $twig->addFunction(new TwigFunction('format_call_path', [self::class, 'formatCallPath']));
        $twig->addFunction(new TwigFunction('tok_row', [self::class, 'tokRow']));
        $twig->addFunction(new TwigFunction('repeat', static fn (string $char, int $n): string => str_repeat($char, max(0, $n))));
    }

    private static function registerFilters(Environment $twig): void
    {
        $twig->addFilter(new TwigFilter('escape_gfm', [self::class, 'escapeGfmCell']));
        $twig->addFilter(new TwigFilter('rel_path', [self::class, 'relativise']));
    }

    public static function kindIcon(string $kind): string
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

    public static function shortKind(string $kind): string
    {
        return match ($kind) {
            'symfony.route'           => 'sr',
            'symfony.command'         => 'sc',
            'symfony.message_handler' => 'smh',
            'symfony.event_listener'  => 'sel',
            'laravel.route'           => 'lr',
            'laravel.command'         => 'lc',
            'laravel.job'             => 'lj',
            'laravel.listener'        => 'll',
            'phpunit.test'            => 'pt',
            default                   => preg_replace('/[^a-z]/', '', strtolower($kind)) ?: 'x',
        };
    }

    public static function reachBadge(?string $confidence): string
    {
        return match ($confidence) {
            'NameOnly'                 => '🎯 direct',
            'Indirect', 'Transitive'   => '🔗 indirect',
            default                    => '·',
        };
    }

    public static function statusBadge(string $status): string
    {
        return match ($status) {
            'ran'     => '✅ ran',
            'skipped' => '⏭ skipped',
            'failed'  => '❌ failed',
            default   => '·',
        };
    }

    /** Truncate a git rev / ref for compact display. */
    public static function shortRev(string $rev): string
    {
        $rev = trim($rev);
        return preg_match('/^[0-9a-f]{8,}$/i', $rev) === 1 ? substr($rev, 0, 8) : $rev;
    }

    /**
     * Render the human-readable name for a row. Falls back to
     * `$fallback` when the row has no `extra.path`.
     *
     * @param array<string,mixed>|null $extra
     */
    public static function formatName(?array $extra, string $fallback): string
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
     * "affected by changed" cell: dedup trigger symbols, one per line.
     *
     * @param list<array<string,mixed>>|null $triggers
     */
    public static function formatTriggers(?array $triggers): string
    {
        $triggers = $triggers ?? [];
        $symbols = array_unique(array_filter(array_column($triggers, 'symbol')));
        if ($symbols === []) {
            return '—';
        }
        return implode('<br>', array_map(static fn (string $s): string => '<code>' . $s . '</code>', $symbols));
    }

    /**
     * "entry point" cell: name + handler FQN + file:line on separate
     * visual lines. Call-chain rendering lives in {@see formatCallPath}
     * so the table can show it in its own column.
     */
    public static function formatEntryPointCell(string $name, string $fqn, string $path, int $line): string
    {
        $lines = [
            '<strong>' . $name . '</strong>',
            '<code>' . $fqn . '</code>',
        ];
        if ($path !== '') {
            $lines[] = '<sub>' . $path . ($line > 0 ? ':' . $line : '') . '</sub>';
        }
        return implode('<br>', $lines);
    }

    /**
     * "path" cell: collapsible call chain from the entry-point handler
     * file down to the changed symbol. Direct hits render as a dash —
     * the entry point's own handler file is in the diff, no traversal
     * happened.
     *
     * @param list<string>|null $reachPath
     */
    public static function formatCallPath(?array $reachPath, string $handlerPath): string
    {
        if ($reachPath === null || $reachPath === []) {
            return '🎯 direct';
        }
        $hops  = count($reachPath);
        $chain = '<code>' . $handlerPath . '</code>';
        foreach ($reachPath as $step) {
            $chain .= '<br>↳ <code>' . $step . '</code>';
        }
        return sprintf(
            '<details><summary>↳ %d hop%s</summary>%s</details>',
            $hops,
            $hops === 1 ? '' : 's',
            $chain,
        );
    }

    /**
     * Render one tab-separated tok row. Path is relativised against
     * `$root` so the output stays diff-friendly across host machines.
     *
     * @param array<string,mixed>|null $extra
     */
    public static function tokRow(string $kindShort, string $name, string $handlerFqn, string $handlerPath, int $handlerLine, ?array $extra, string $root): string
    {
        $path = $handlerPath !== '' ? self::relativise($handlerPath, $root) : '';
        if ($path !== '' && $handlerLine > 0) {
            $path .= ':' . $handlerLine;
        }
        return implode("\t", [
            $kindShort,
            $name,
            $handlerFqn,
            $path,
            self::formatExtra($extra),
        ]);
    }

    /** @param array<string,mixed>|null $extra */
    private static function formatExtra(?array $extra): string
    {
        if (!is_array($extra)) {
            return '';
        }
        if (isset($extra['path'])) {
            $methods = isset($extra['methods']) && is_array($extra['methods'])
                ? implode('|', $extra['methods'])
                : '';
            return $methods !== '' ? "{$methods} {$extra['path']}" : (string) $extra['path'];
        }
        if (isset($extra['message'])) {
            return (string) $extra['message'];
        }
        return '';
    }

    public static function relativise(string $path, string $root): string
    {
        $real = realpath($path);
        $rootReal = realpath($root) ?: $root;
        $candidate = $real !== false ? $real : $path;
        if (str_starts_with($candidate, $rootReal . DIRECTORY_SEPARATOR)) {
            return substr($candidate, strlen($rootReal) + 1);
        }
        return $path;
    }

    /**
     * GFM table cells can't contain literal pipes or newlines. Pipes
     * inside `<details>` (e.g. inside route names like `GET|POST /x`)
     * need to be escaped so the table parser doesn't see a column break.
     * Newlines are allowed only as `<br>`.
     */
    public static function escapeGfmCell(string $cell): string
    {
        $cell = str_replace(["\r\n", "\n", "\r"], '<br>', $cell);
        return str_replace('|', '\\|', $cell);
    }
}
