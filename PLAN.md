# PHP rewrite — plan & status

## Why we pivoted from Rust

The Rust + mago build was fast (1s on a 4437-file Laravel project) but
hit a hard accuracy ceiling on Laravel's interface-DI patterns: mago's
static analyser cannot resolve `$this->repo->find()` when `$repo` is
typed as a contract bound to a concrete in the container. The
file-level reach fallback masked this for the common case (Controller
file in diff → route flagged), but the moment we wanted runtime
fidelity (`bin/console debug:router --format=json`,
`php artisan route:list --json`) we were going to shell into PHP
anyway.

If PHP runtime is always available — and for "tool I install in the
project I'm working on" it always is — boot the kernel and ask the
framework directly. No static-analysis approximation. No version drift
between mago and the user's actual Symfony / Laravel.

## Architecture

Composer monorepo, three packages.

- `watson/core`   — framework-agnostic primitives. Multi-analysis
                    `Envelope`, `EntryPoint` value object, `DiffSpec`
                    (git-diff revision shapes), `GitDiff` (shells
                    `git diff --name-only`), `FileLevelReach`
                    (file-in-diff intersection), `ClassScanner`
                    (filesystem PHP-class discovery), `PhpUnitCollector`,
                    `Renderer` (json/md/text). No knowledge of Laravel
                    or Symfony.
- `watson/laravel`— Laravel service provider + two Artisan commands.
                    Pulls runtime routes via `Route::getRoutes()` and
                    commands via `Artisan::all()`. Filesystem-discovers
                    jobs (`ShouldQueue`), listeners (`App\Listeners\*`),
                    PHPUnit tests. Auto-registered via
                    `extra.laravel.providers`.
- `watson/symfony`— Symfony bundle + console commands. Routes via
                    `RouterInterface`, commands via the running
                    `Application` (LazyCommand-aware). Same PHPUnit
                    discovery via shared core helper.

User installs the framework adapter:

```
composer require --dev watson/laravel
# or
composer require --dev watson/symfony
```

Auto-discovery wires up the commands; no manual config.

## Status — v0.2.0-dev

### Shipped

- [x] **`watson/core`** — `Envelope`, `EntryPoint`, `Source` enum,
      `Renderer` (json/md/text with per-kind grouping), `DiffSpec`
      (no-arg / `--cached` / `<rev>` / `<a>..<b>` / `<a>...<b>`),
      `GitDiff`, `FileLevelReach`, `ClassScanner`, `PhpUnitCollector`,
      `Analysis\Blastradius`. 21 PHPUnit tests covering all of the
      above against a real tempdir git repo for `DiffSpec`.
- [x] **`watson/laravel`** — `WatsonServiceProvider` auto-registers
      both commands. `RouteCollector` (routes + commands runtime),
      `JobCollector`, `ListenerCollector`, `Collector` facade with
      `--scope=routes|all` flag.
- [x] **`watson/symfony`** — `WatsonBundle`, `WatsonExtension`,
      `RouteCollector` (routes + commands via `Application::all()`,
      unwrapping `LazyCommand`), `Collector` facade.
- [x] **Behat** — 6 hermetic scenarios across both fixtures. One
      `@smoke`-tagged scenario runs against
      `WATSON_EASY_PLU_ROOT=...` for opt-in real-app validation.
- [x] **Verbosity tier** — `-v` flag emits a one-line stderr summary
      of entry-point counts on both adapters.
- [x] **Markdown / text output** — per-kind sections in stable order
      (`symfony.route` → `symfony.command` → `laravel.route` → …),
      route handlers + HTTP method/path inline.
- [x] **Hermetic fixtures** — Laravel 11 micro-app (`bootstrap/app.php`
      + `routes/web.php` + a controller / command / job / listener /
      PHPUnit test), Symfony 7 micro-kernel (`MicroKernelTrait` +
      attribute routes + a fixture command).

### Deferred to v0.3

- AST static fallback (`nikic/php-parser` based detector) for projects
  whose kernel can't boot — useful for CI against detached
  worktrees. Runtime registries cover the high-value case.
- PHPStan-driven type-aware reach. Opt-in flag, kicks in only when the
  user already has `phpstan/phpstan` in `require-dev`.
- Symfony messenger handlers / event subscribers via container
  introspection.
- Laravel scheduled tasks via `Schedule::events()` runtime call.
- Two-commit diffs where head-side ≠ HEAD/working-tree (current build
  errors fast; future build uses `git worktree add` into a tempdir).
- Mailables / Notifications / Broadcast channels.
- `bin/watson` standalone CLI for projects without a framework
  adapter.

## Optional PHPStan integration (v0.3)

PHPStan would help the `watson/core` reach pass in two ways:

1. **Type inference** — replace file-level reach with method-level
   reach by resolving `$this->repo->find()` to a concrete handler via
   PHPStan's `Scope`/`Type` system.
2. **Reflection** — `PHPStan\Reflection\ReflectionProvider` is a
   battle-tested, version-aware reflection layer; better than booting
   `ReflectionClass` and hoping autoloaders work.

Plan: if `phpstan/phpstan` is in the user's `require-dev`, watson
opportunistically enriches reach with PHPStan's type info. Otherwise
fall back to file-level reach. Keeps watson installable without
forcing PHPStan as a hard dep.

## Drop Rust

Done. `legacy-rust/` deleted from `main` after the PHP rewrite reached
parity. The original Rust source survives at the `rust-archive` git
tag for historical reference.

Git plumbing stays in PHP — `Symfony\Component\Process` for shelling
`git`, plus a tiny diff-parser on top. The Rust `gix`/`git2` story
gave us nothing the shell-out doesn't.

## CLI surface

```
# Laravel
php artisan watson:blastradius     [<rev>[..<rev2>|...<rev2>]] [--cached] [--scope=routes|all] [--format json|md|text]
php artisan watson:list-entrypoints                                       [--scope=routes|all] [--format json|md|text]

# Symfony
php bin/console watson:blastradius     [<rev>[..<rev2>|...<rev2>]] [--cached] [--scope=routes|all] [--format json|md|text]
php bin/console watson:list-entrypoints                                       [--scope=routes|all] [--format json|md|text]
```

Same `git diff` revision shapes (`..`, `...`, `--cached`, no-arg). Same
multi-analysis envelope shape across both adapters.
