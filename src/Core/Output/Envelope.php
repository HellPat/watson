<?php

declare(strict_types=1);

namespace Watson\Core\Output;

/**
 * Multi-analysis envelope. Top-level fields (`tool`, `version`, `language`,
 * `context`, `sources`) are emitted once per invocation; each analysis
 * (`blastradius`, `list-entrypoints`, …) appends its own `result` block to
 * `analyses[]`.
 *
 * `sources` is the chain-of-discovery report produced by
 * {@see \Watson\Cli\ChainedEntrypointResolver}: one entry per
 * `EntrypointSource` showing whether it ran, skipped, or failed. The
 * old `framework` label was dropped in favour of this per-source
 * attribution.
 */
final class Envelope implements \JsonSerializable
{
    public const TOOL = 'watson';
    public const TOOL_VERSION = '0.4.0';

    /** @var list<array<string,mixed>> */
    private array $analyses = [];

    /** @var list<SourceStatus> */
    private array $sources = [];

    public function __construct(
        public readonly string $language,
        public readonly string $rootPath,
        public readonly ?string $base = null,
        public readonly ?string $head = null,
    ) {
    }

    /** @param list<SourceStatus> $sources */
    public function setSources(array $sources): void
    {
        $this->sources = $sources;
    }

    /** @return list<SourceStatus> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @param array<string,mixed> $result */
    public function pushAnalysis(string $name, string $version, array $result): void
    {
        $this->analyses[] = [
            'name' => $name,
            'version' => $version,
            'ok' => true,
            'result' => $result,
        ];
    }

    public function pushFailedAnalysis(string $name, string $version, string $message, string $kind = 'error'): void
    {
        $this->analyses[] = [
            'name' => $name,
            'version' => $version,
            'ok' => false,
            'error' => ['kind' => $kind, 'message' => $message],
        ];
    }

    public function jsonSerialize(): array
    {
        $context = ['root' => $this->rootPath];
        if ($this->base !== null) {
            $context['base'] = $this->base;
        }
        if ($this->head !== null) {
            $context['head'] = $this->head;
        }

        return [
            'tool' => self::TOOL,
            'version' => self::TOOL_VERSION,
            'language' => $this->language,
            'context' => $context,
            'sources' => array_map(static fn (SourceStatus $s) => $s->jsonSerialize(), $this->sources),
            'analyses' => $this->analyses,
        ];
    }
}
