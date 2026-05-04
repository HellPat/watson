# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- watson is now a **standalone CLI** (`vendor/bin/watson`). No bundle, no service provider, no `config/bundles.php` entry.
- Route + command discovery is **outside-in** — shells out to `bin/console debug:router --format=json` (Symfony) and `php artisan route:list --json` (Laravel). watson code never runs inside the target kernel.
- Reflection backend uses [`roave/better-reflection`](https://github.com/Roave/BetterReflection); watson never `require_once`s your app's source.

## [0.3.0] — 2026-05-03

**One package, both frameworks.** Collapsed `watson/core`, `watson/laravel`, `watson/symfony` into a single `hellpat/watson` package. Detection logic, output renderers, and both adapter shells now ship in one composer install.

### Changed

- **BREAKING** Package name: `watson/laravel` + `watson/symfony` → **`hellpat/watson`**.
- **BREAKING** Single-package layout: source moved from `packages/{core,laravel,symfony}/src/` to `src/{Core,Laravel,Symfony}/`. Namespaces unchanged (`Watson\Core\*`, `Watson\Laravel\*`, `Watson\Symfony\*`).
- **BREAKING** Symfony users now register the Bundle one-line in `config/bundles.php` (previously implicit via Symfony Flex on `watson/symfony`'s `type: symfony-bundle`).
- Laravel auto-discovery still works (`extra.laravel.providers`), no user action required.
- `symfony/console` + `symfony/process` are required deps; framework-specific (`illuminate/*`, `symfony/framework-bundle`, `symfony/routing`) live in `suggest`.

### Migration

```diff
- composer require --dev watson/laravel
+ composer require --dev hellpat/watson
```

```diff
- composer require --dev watson/symfony
+ composer require --dev hellpat/watson
```

Symfony users add to `config/bundles.php`:

```php
return [
    // …
    Watson\Symfony\WatsonBundle::class => ['all' => true],
];
```

## [0.2.0] — 2026-05-02

First composer release. Three-package split (`watson/core`, `watson/laravel`, `watson/symfony`) — superseded in v0.3.0.

### Added

- `EntryPoint` value object + `Source` enum.
- `Envelope` multi-analysis JSON shape.
- `Renderer` for `json`, `md`, `text`.
- `DiffSpec` for git-diff revision shapes (`<a>..<b>`, `<a>...<b>`, `--cached`, no-arg).
- `GitDiff`, `FileLevelReach`, `ClassScanner`, `PhpUnitCollector`, `Analysis\Blastradius`.
- Laravel adapter: `RouteCollector`, `JobCollector`, `ListenerCollector`, `--scope=routes|all`.
- Symfony adapter: `RouteCollector` (LazyCommand-aware), `--scope=routes|all`.
- 21 PHPUnit unit tests + 6 hermetic Behat scenarios.
- CI on PHP 8.2 / 8.3 / 8.4.

### Removed

- Rust prototype deleted from `main`. Source preserved at the `rust-archive` git tag.

[Unreleased]: https://github.com/HellPat/watson/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/HellPat/watson/releases/tag/v0.3.0
[0.2.0]: https://github.com/HellPat/watson/releases/tag/v0.2.0
