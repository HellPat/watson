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
    ) {
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

        return $out;
    }
}
