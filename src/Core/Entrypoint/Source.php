<?php

declare(strict_types=1);

namespace Watson\Core\Entrypoint;

/**
 * Provenance of a detected entry point. Used for dedup priority and to let
 * downstream consumers filter by trust level.
 *
 *   Runtime          framework-booted authoritative source (e.g. Laravel's
 *                    `Route::getRoutes()` after the kernel has wired up all
 *                    service providers). Highest fidelity.
 *   Attribute        PHP `#[Route]` / `#[AsCommand]` / etc. on the source.
 *   Interface        marker interface or base class (`extends Command`,
 *                    `implements ShouldQueue`, …).
 *   StaticCall       facade-call detection, e.g. `Route::get(...)` in
 *                    `routes/*.php`.
 */
enum Source: string
{
    case Runtime = 'runtime';
    case Attribute = 'attribute';
    case Interface_ = 'interface';
    case StaticCall = 'static-call';

    public function priority(): int
    {
        return match ($this) {
            self::Runtime => 4,
            self::Attribute => 3,
            self::Interface_ => 2,
            self::StaticCall => 1,
        };
    }
}
