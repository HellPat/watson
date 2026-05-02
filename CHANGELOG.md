# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] — 2026-05-02

First release of the PHP composer build. Replaces the v0.1 Rust prototype.

### Added

- **`watson/core`** — framework-agnostic primitives.
  - `EntryPoint` value object + `Source` enum (`runtime` | `attribute` | `interface` | `static-call`).
  - `Envelope` multi-analysis JSON shape (`tool`, `version`, `language`, `framework`, `context`, `analyses[]`).
  - `Renderer` for `json`, `md`, `text` outputs with stable per-kind grouping.
  - `DiffSpec` resolves `git diff`-shaped revision arguments: no-arg, `--cached` / `--staged`, `<rev>`, `<a> <b>`, `<a>..<b>`, `<a>...<b>` (merge-base).
  - `GitDiff` shells `git diff --name-only` for the resolved spec.
  - `FileLevelReach` intersects entry-point handler files with the diff.
  - `ClassScanner` walks a directory for PHP files and reflects each declared class (autoload-safe).
  - `PhpUnitCollector` discovers `PHPUnit\Framework\TestCase` subclasses + `test*` methods or `#[Test]` attributes.
  - `Analysis\Blastradius` orchestrates the diff intersection + emits the analysis result.
- **`watson/laravel`** — Laravel adapter.
  - Auto-registers via `extra.laravel.providers`.
  - `watson:list-entrypoints` and `watson:blastradius` Artisan commands.
  - `RouteCollector` pulls `Route::getRoutes()` + `Artisan::all()` (vendor commands filtered).
  - `JobCollector` (filesystem walk for `ShouldQueue` impls in `app/Jobs/`).
  - `ListenerCollector` (filesystem walk for `app/Listeners/*` with `handle()`/`__invoke`).
  - `--scope=routes|all` flag.
  - `-v` verbosity tier emits a one-line stderr summary.
- **`watson/symfony`** — Symfony adapter.
  - Auto-registers as a Bundle.
  - `watson:list-entrypoints` and `watson:blastradius` console commands.
  - `RouteCollector` pulls `RouterInterface::getRouteCollection()` + `Application::all()`, unwrapping `LazyCommand` so service-tag commands surface with their real handler class.
  - `--scope=routes|all` flag.
  - `-v` verbosity tier.
- **Tests**.
  - 21 PHPUnit unit tests against `watson/core` (incl. real-tempdir-git-repo `DiffSpec` coverage).
  - 6 hermetic Behat scenarios covering both adapters across `list-entrypoints` (default + `--scope=all`) and `blastradius` (working-tree diff).
  - Opt-in `@smoke` Behat scenario gated by `WATSON_EASY_PLU_ROOT` for real-app validation.

### Removed

- Rust prototype deleted from `main`. Source preserved at the `rust-archive` git tag.

[Unreleased]: https://github.com/HellPat/watson/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/HellPat/watson/releases/tag/v0.2.0
