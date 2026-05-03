# PHP rewrite — plan & status

## Why we pivoted from Rust

The Rust + mago build was fast (1s on a 4437-file Laravel project) but hit a hard accuracy ceiling on Laravel's interface-DI patterns: mago's static analyser cannot resolve `$this->repo->find()` when `$repo` is typed as a contract bound to a concrete in the container. The file-level reach fallback masked this for the common case (Controller file in diff → route flagged), but the moment we wanted runtime fidelity (`bin/console debug:router --format=json`, `php artisan route:list --json`) we were going to shell into PHP anyway.

If PHP runtime is always available — and for "tool I install in the project I'm working on" it always is — boot the kernel and ask the framework directly. No static-analysis approximation. No version drift between mago and the user's actual Symfony / Laravel.

## Architecture

**Single composer package** (`hellpat/watson`) with three internal namespaces:

- `Watson\Core\*` — framework-agnostic primitives: multi-analysis `Envelope`, `EntryPoint` value object, `Source` enum, `DiffSpec` (git-diff revision shapes), `GitDiff`, `FileLevelReach`, `ClassScanner` (filesystem PHP-class discovery), `PhpUnitCollector`, `Renderer` (json/md/text), `Analysis\Blastradius`. No framework knowledge.
- `Watson\Laravel\*` — Laravel `WatsonServiceProvider` + two Artisan commands. Pulls routes via `Route::getRoutes()`, commands via `Artisan::all()`. Filesystem-discovers jobs (`ShouldQueue`), listeners (`app/Listeners/*`).
- `Watson\Symfony\*` — Symfony `WatsonBundle` + two console commands. Routes via `RouterInterface`, commands via `Application::all()` (LazyCommand-aware).

User installs once:

```
composer require --dev hellpat/watson
```

Laravel auto-registers via `extra.laravel.providers`. Symfony users add the Bundle one-line in `config/bundles.php`. Framework-specific deps (`illuminate/*`, `symfony/framework-bundle`, `symfony/routing`) live in `suggest`; the user's project already has them.

## Status — v0.3.0

### Shipped

- [x] **Single-package layout.** `hellpat/watson` ships both adapter shells. PSR-4 `Watson\\` → `src/`.
- [x] **`Watson\Core\*`** — `Envelope`, `EntryPoint`, `Source`, `Renderer` (per-kind grouping), `DiffSpec` (no-arg / `--cached` / `<rev>` / `<a>..<b>` / `<a>...<b>`), `GitDiff`, `FileLevelReach`, `ClassScanner`, `PhpUnitCollector`, `Analysis\Blastradius`. 21 PHPUnit tests covering all of the above against a real tempdir git repo for `DiffSpec`.
- [x] **`Watson\Laravel\*`** — `WatsonServiceProvider` auto-registers both commands. `RouteCollector` (routes + commands runtime), `JobCollector`, `ListenerCollector`, `Collector` facade with `--scope=routes|all`.
- [x] **`Watson\Symfony\*`** — `WatsonBundle`, `WatsonExtension`, `RouteCollector` (routes + commands via `Application::all()`, unwrapping `LazyCommand`), `Collector` facade.
- [x] **Behat** — 6 hermetic scenarios across both fixtures. One `@smoke`-tagged scenario gated by `WATSON_EASY_PLU_ROOT` for opt-in real-app validation.
- [x] **Verbosity tier** — `-v` flag emits a one-line stderr summary on both adapters.
- [x] **Markdown / text output** — per-kind sections in stable order, route handlers + HTTP method/path inline.

### Deferred to v0.4

- AST static fallback (`nikic/php-parser` based detector) for projects whose kernel can't boot.
- PHPStan-driven type-aware reach. Opt-in flag, kicks in only when the user already has `phpstan/phpstan` in `require-dev`.
- Symfony messenger handlers / event subscribers via container introspection.
- Laravel scheduled tasks via `Schedule::events()` runtime call.
- Two-commit diffs where head-side ≠ HEAD/working-tree (current build errors fast; future build uses `git worktree add` into a tempdir).
- Mailables / Notifications / Broadcast channels.
- `bin/watson` standalone CLI for projects without a framework adapter.
- Symfony Flex recipe for true zero-config Bundle registration.

## Drop Rust

Done. `legacy-rust/` deleted from `main`. The original Rust source survives at the `rust-archive` git tag.

Git plumbing stays in PHP — `Symfony\Component\Process` for shelling `git`, plus a tiny diff-parser on top.

## CLI surface

```
# Laravel
php artisan watson:blastradius     [<rev>[..<rev2>|...<rev2>]] [--cached] [--scope=routes|all] [--format json|md|text]
php artisan watson:list-entrypoints                                       [--scope=routes|all] [--format json|md|text]

# Symfony
php bin/console watson:blastradius     [<rev>[..<rev2>|...<rev2>]] [--cached] [--scope=routes|all] [--format json|md|text]
php bin/console watson:list-entrypoints                                       [--scope=routes|all] [--format json|md|text]
```

Same `git diff` revision shapes (`..`, `...`, `--cached`, no-arg). Same multi-analysis envelope shape across both adapters.
