<?php

declare(strict_types=1);

namespace Watson\Cli;

/**
 * Strongly-typed options passed to every
 * {@see \Watson\Cli\Source\EntrypointSource} via the chain. Replaces
 * the loose `array $opts` shape that used to live on the dispatcher.
 */
final class ResolverOptions
{
    public const SCOPE_ROUTES = 'routes';
    public const SCOPE_ALL    = 'all';

    public function __construct(
        /** `routes` or `all` — non-route sources short-circuit when scope is `routes`. */
        public readonly string $scope = self::SCOPE_ALL,
        /** Value passed to `bin/console` / `artisan` when collecting routes. */
        public readonly string $appEnv = 'dev',
    ) {
    }

    public function wantsRoutesOnly(): bool
    {
        return $this->scope === self::SCOPE_ROUTES;
    }
}
