# watson

PR blast-radius analyzer for PHP, shipped as a Composer dev-dep. Tells a code reviewer (human or AI) which application entry points a diff reaches — routes, console commands, queued jobs, event listeners, PHPUnit tests — so the review can focus on the surface that actually moved.

Two adapter packages plug in via auto-discovery:

- **`watson/laravel`** — registers `watson:list-entrypoints` and `watson:blastradius` Artisan commands.
- **`watson/symfony`** — registers the same commands as a Bundle on `bin/console`.

Both rely on **runtime registry introspection**: Laravel's `Route::getRoutes()` / `Artisan::all()`, Symfony's `RouterInterface::getRouteCollection()` / `Application::all()`. Whatever the framework actually wired up at boot — including YAML routes, package-shipped commands, service-tag handlers — appears in the output. No AST guessing.

## Install

```bash
# Laravel
composer require --dev watson/laravel

# Symfony
composer require --dev watson/symfony
```

Both packages auto-register on install (Laravel via `extra.laravel.providers`, Symfony as a Bundle). Nothing to wire up.

## Commands

### `watson:list-entrypoints`

Snapshot every entry point the framework registered.

```bash
php artisan watson:list-entrypoints                  # default scope=all (routes + commands + jobs + listeners + tests)
php artisan watson:list-entrypoints --scope=routes   # routes only — fastest
php artisan watson:list-entrypoints --format=md      # markdown for PR descriptions
php bin/console watson:list-entrypoints              # Symfony equivalent
```

Output is a JSON envelope; `--format=md|text` are also available.

### `watson:blastradius`

Report entry points whose handler files are touched by a diff.

```bash
# git-diff-shaped revision surface
php artisan watson:blastradius                       # working tree vs HEAD
php artisan watson:blastradius --cached              # staged index vs HEAD
php artisan watson:blastradius <rev>                 # working tree vs <rev>
php artisan watson:blastradius <a> <b>               # <a> vs <b>
php artisan watson:blastradius <a>..<b>              # same as `<a> <b>`
php artisan watson:blastradius <a>...<b>             # merge-base(a, b) vs <b>
```

Pipe `--format=md` into a PR description or an LLM-driven reviewer.

## What watson detects

| kind                 | source                                                                                    |
| ---                  | ---                                                                                       |
| `laravel.route`      | `Route::getRoutes()` runtime registry (every YAML / closure / `Route::resource()` route)  |
| `laravel.command`    | `Artisan::all()` runtime registry, vendor commands filtered out                           |
| `laravel.job`        | `app/Jobs/` filesystem walk for classes implementing `Illuminate\Contracts\Queue\ShouldQueue` |
| `laravel.listener`   | `app/Listeners/` filesystem walk for classes with `handle()` or `__invoke`                |
| `symfony.route`      | `RouterInterface::getRouteCollection()` (covers attribute / YAML / XML / PHP-config routes) |
| `symfony.command`    | `Application::all()`, vendor commands filtered out                                        |
| `phpunit.test`       | `tests/` filesystem walk for `PHPUnit\Framework\TestCase` subclasses                      |

## Output shape

JSON envelope mirrors the multi-analysis schema downstream tooling expects:

```json
{
  "tool": "watson",
  "version": "0.2.0-dev",
  "language": "php",
  "framework": "laravel",
  "context": {"root": "/abs/path", "base": "main", "head": "<working tree>"},
  "analyses": [
    {
      "name": "blastradius",
      "version": "0.2.0-dev",
      "ok": true,
      "result": {
        "summary": {"files_changed": 1, "entry_points_affected": 2},
        "affected_entry_points": [
          {"kind": "laravel.route", "name": "show", "handler": {...}, "extra": {...}, "min_confidence": "NameOnly"}
        ]
      }
    }
  ]
}
```

## Reach algorithm

watson uses **file-level reach**: an entry point is "affected" iff its handler file is in the diff. High recall, modest precision (a docblock-only edit shows up). Confidence is reported as `NameOnly` so consumers can filter accordingly. This is deliberately the only reach algorithm — production traffic showed it's the only signal that holds up against heavy interface-DI codebases (Laravel especially).

## Verbosity

Both commands honour the standard Symfony Console `-v` flag:

```bash
php artisan watson:blastradius main..HEAD -v
# stderr: watson: 184 entry points · diff main..HEAD
```

JSON / markdown output stays clean on stdout regardless.

## Scope

`--scope=routes` keeps the run cheap — no filesystem walks, no reflection on app/Jobs / app/Listeners / tests. Right for tight CI loops. `--scope=all` (default) walks the conventional directories and adds jobs / listeners / PHPUnit tests on top.

## Repository layout

```
watson/
├── packages/
│   ├── core/              # framework-neutral primitives (Envelope, EntryPoint, DiffSpec, FileLevelReach, ClassScanner, PhpUnitCollector)
│   ├── laravel/           # Artisan commands + RouteCollector / JobCollector / ListenerCollector + ServiceProvider
│   └── symfony/           # Console commands + RouteCollector + Bundle
├── fixtures/
│   ├── laravel-app/       # Hermetic Laravel 11 app — Behat scenarios run against this
│   └── symfony-app/       # Hermetic Symfony 7 micro-kernel
├── features/              # Behat scenarios + step definitions
├── composer.json          # workspace root with path repos + autoload-dev
└── phpunit.xml.dist       # core unit tests
```

## Dev workflow

```bash
composer install                            # workspace + adapters via path repos
vendor/bin/phpunit                          # 21 unit tests on core
vendor/bin/behat                            # 6 hermetic scenarios across both fixtures

# Optional smoke against a real Laravel app
WATSON_EASY_PLU_ROOT=~/easy-plu/backend vendor/bin/behat --tags=smoke
```

## Status

**v0.2.0-dev** — usable. PHP composer rewrite of the Rust v0.1 prototype. Runtime registries cover the high-value 80% of real-world entry points; static AST fallback is deferred to v0.3. PHPStan-driven type-aware reach lives behind a future opt-in flag. See `PLAN.md` for the roadmap.
