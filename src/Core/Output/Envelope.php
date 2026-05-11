<?php

declare(strict_types=1);

namespace Watson\Core\Output;

/**
 * Multi-analysis envelope. Top-level fields (`tool`, `version`, `language`,
 * `framework`, `context`) are emitted once per invocation; each analysis
 * (`blastradius`, `list-entrypoints`, …) appends its own `result` block to
 * `analyses[]`. Backwards-compatible with the Rust schema.
 */
final class Envelope implements \JsonSerializable
{
    public const TOOL = 'watson';
    public const TOOL_VERSION = '0.4.0';

    /** @var list<array<string,mixed>> */
    private array $analyses = [];

    public function __construct(
        public readonly string $language,
        public readonly string $framework,
        public readonly string $rootPath,
        public readonly ?string $base = null,
        public readonly ?string $head = null,
    ) {
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
            'framework' => $this->framework,
            'context' => $context,
            'analyses' => $this->analyses,
        ];
    }
}
