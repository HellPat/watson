<?php

declare(strict_types=1);

namespace Watson\Core\Diff;

/**
 * Method-level change payload — the unit blast-radius now flows on.
 *
 * Three shapes, ordered from most to least precise:
 *
 *   ($classFqn, $methodName)   → "Class::method" semantically changed
 *   ($classFqn, null)          → "Class::*" — class-body edit (property,
 *                                constant, trait body) outside any method,
 *                                couples to the whole class
 *   (null,      null)          → file-level fallback — top-of-file `use`,
 *                                namespaced function, or no-hunk input
 *                                (`--files=`, `--name-only`)
 */
final class ChangedSymbol implements \JsonSerializable
{
    public function __construct(
        /** Absolute path to the post-image file (or pre-image when deleted). */
        public readonly string $filePath,
        public readonly ?string $classFqn,
        public readonly ?string $methodName,
        public readonly int $startLine,
        public readonly int $endLine,
    ) {
    }

    /**
     * Human-readable identity used in renderer cells and JSON. Never empty.
     *
     *   ChangedSymbol('…/Foo.php', 'App\Foo', 'bar', …) → "App\Foo::bar"
     *   ChangedSymbol('…/Foo.php', 'App\Foo', null,  …) → "App\Foo::*"
     *   ChangedSymbol('…/x.php',   null,      null,  …) → "x.php"
     */
    public function symbol(): string
    {
        if ($this->classFqn !== null && $this->methodName !== null) {
            return $this->classFqn . '::' . $this->methodName;
        }
        if ($this->classFqn !== null) {
            return $this->classFqn . '::*';
        }

        return basename($this->filePath);
    }

    public function jsonSerialize(): array
    {
        return [
            'symbol' => $this->symbol(),
            'file' => $this->filePath,
            'class' => $this->classFqn,
            'method' => $this->methodName,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
        ];
    }
}
