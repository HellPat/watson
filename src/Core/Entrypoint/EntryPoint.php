<?php

declare(strict_types=1);

namespace Watson\Core\Entrypoint;

/**
 * One application entry point. Framework-neutral shape — Laravel routes,
 * Symfony commands, PHPUnit tests, queued jobs all hydrate this.
 *
 * Mirrors the Rust `EntryPoint` struct one-for-one so JSON consumers
 * (LLMs, jq pipelines, downstream tooling) see no schema drift across
 * the rewrite.
 */
final class EntryPoint implements \JsonSerializable
{
    public function __construct(
        /** Stable display kind, e.g. "laravel.route", "symfony.command", "phpunit.test". */
        public readonly string $kind,
        /** Human-readable identity: route name, command name, or handler FQN as fallback. */
        public readonly string $name,
        public readonly string $handlerFqn,
        public readonly string $handlerPath,
        public readonly int $handlerLine,
        public readonly Source $source,
        /** Kind-specific metadata: HTTP method/path, cron expression, frequency. Null when none. */
        public readonly ?array $extra = null,
        /**
         * Name of the {@see \Watson\Cli\Source\EntrypointSource} that
         * emitted this row, e.g. `laravel.routes`. Null only for fixtures
         * / legacy callers that didn't go through the chain.
         */
        public readonly ?string $discoveredBy = null,
    ) {
    }

    /**
     * Return a clone tagged with the source name. Used by
     * {@see \Watson\Cli\ChainedEntrypointResolver} to stamp every row
     * with the source that produced it.
     */
    public function withDiscoveredBy(string $sourceName): self
    {
        return new self(
            kind: $this->kind,
            name: $this->name,
            handlerFqn: $this->handlerFqn,
            handlerPath: $this->handlerPath,
            handlerLine: $this->handlerLine,
            source: $this->source,
            extra: $this->extra,
            discoveredBy: $sourceName,
        );
    }

    public function jsonSerialize(): array
    {
        $out = [
            'kind' => $this->kind,
            'name' => $this->name,
            'handler_fqn' => $this->handlerFqn,
            'handler_path' => $this->handlerPath,
            'handler_line' => $this->handlerLine,
            'source' => $this->source->value,
        ];
        if ($this->extra !== null && $this->extra !== []) {
            $out['extra'] = $this->extra;
        }
        if ($this->discoveredBy !== null) {
            $out['discovered_by'] = $this->discoveredBy;
        }

        return $out;
    }
}
