# watson

> PR blast-radius analyzer for PHP. One Composer dev-dep. Tells your reviewer — human or AI — which routes, commands, jobs, listeners, and tests a diff actually reaches. Ships both Laravel and Symfony adapters in one package.

[![ci](https://github.com/HellPat/watson/actions/workflows/ci.yml/badge.svg)](https://github.com/HellPat/watson/actions/workflows/ci.yml)
[![packagist](https://img.shields.io/packagist/v/hellpat/watson.svg)](https://packagist.org/packages/hellpat/watson)
[![license](https://img.shields.io/github/license/HellPat/watson.svg)](LICENSE)

```bash
$ php artisan watson:blastradius main..HEAD --format=md
```

```markdown
# watson — php laravel
_tool watson v0.3.0_

Diff: `b7c570f` → `HEAD`
Root: `/abs/path/project`

## blastradius
**Summary** — 3 files changed · 5 entry points affected

### Affected entry points (5)

#### laravel.route (2)
##### users.show
- **Handler**: `App\Http\Controllers\UserController::show` (`app/Http/Controllers/UserController.php:42`)
- **HTTP**: GET `/users/{id}`
…

#### laravel.job (1)
##### App\Jobs\NotifyUserJob
- **Handler**: `App\Jobs\NotifyUserJob::handle` (`app/Jobs/NotifyUserJob.php:18`)
```

---

## Why watson

Code reviewers — and increasingly LLM reviewers — drown in diff context. A 30-line refactor inside a service can affect zero routes or fifty. The PR says nothing about which.

watson answers in one shell: take the diff, ask the framework for its runtime entry-point registry, intersect, report. The output is a JSON envelope (or markdown / plain text) that drops straight into a PR description, a CI annotation, or an AI-reviewer prompt.

**Runtime first, no AST guessing.** watson boots the user's actual `app('router')` / `RouterInterface`, walks `Artisan::all()` / `Application::all()`, reflects `app/Jobs` and `app/Listeners`. Whatever the framework wired up at boot — YAML routes, package-shipped commands, service-tag handlers, `Route::resource()` expansion — appears in the output. No version drift between watson and the framework.

---

## Install

```bash
composer require --dev hellpat/watson
```

That single package ships both adapters. `composer install` resolves to whatever framework is already in your project.

### Laravel

Nothing else. The `WatsonServiceProvider` auto-registers via `extra.laravel.providers`. Run `php artisan watson:list-entrypoints` and you're in.

### Symfony

Add one line to `config/bundles.php`:

```php
return [
    // …
    Watson\Symfony\WatsonBundle::class => ['all' => true],
];
```

Then `php bin/console watson:list-entrypoints`.

**Requirements:** PHP 8.4+, git on `$PATH`. Laravel 10/11/12 or Symfony 6.4/7.x. No extensions beyond `ext-json`. Required runtime deps: `symfony/console`, `symfony/process`. Framework-specific deps stay in `suggest` — your project already has them.

---

## Commands

### `watson:blastradius`

Report entry points whose handler files are touched by a diff. Revision surface mirrors `git diff` exactly.

| invocation                                | meaning                              | when to use                                                                          |
| ---                                       | ---                                  | ---                                                                                  |
| `watson:blastradius`                      | working tree vs HEAD                 | pre-push gut check — what does the WIP touch?                                         |
| `watson:blastradius --cached`             | staged index vs HEAD                 | pre-commit hook — gate the commit you're about to make                                |
| `watson:blastradius <rev>`                | working tree vs `<rev>`              | "what diverges from `<rev>` right now?" Working-tree dirt included.                   |
| `watson:blastradius <a> <b>`              | `<a>` vs `<b>`                       | compare two refs; head must equal HEAD/working-tree (file-level reach reads on disk). |
| `watson:blastradius <a>..<b>`             | same as `<a> <b>`                    | git's range form — common in CI scripts already using `<branch>..HEAD`.               |
| `watson:blastradius <a>...<b>`            | merge-base(`<a>`,`<b>`) vs `<b>`     | **PR review brief.** Matches GitHub's "Files changed" view exactly.                   |

Pick the form that matches what you mean: `..` if you want diff between two specific commits, `...` if you want "PR-shaped" diff that ignores whatever happened on the base branch since the feature branch forked.

Flags:

| flag                      | default | meaning                                                       |
| ---                       | ---     | ---                                                           |
| `--format=json\|md\|text` | `json`  | output format                                                 |
| `--scope=routes\|all`     | `all`   | `routes` = cheapest; `all` adds jobs / listeners / tests      |
| `-v`                      | —       | one-line stderr summary; stdout stays clean                   |

### `watson:list-entrypoints`

Snapshot every entry point the framework registered. Same `--format` and `--scope` flags.

```bash
$ php artisan watson:list-entrypoints --scope=all --format=text
=====================================================================
watson php laravel (root: /abs/path/project)
=====================================================================

[list-entrypoints]
  9 entry point(s):
    - laravel.route            home                           App\Http\Controllers\HelloController::home
    - laravel.route            hello                          App\Http\Controllers\HelloController::hello
    - laravel.command          app:ping                       App\Console\Commands\PingCommand::handle
    - laravel.job              App\Jobs\PingJob               App\Jobs\PingJob::handle
    - laravel.listener         App\Listeners\LogPing          App\Listeners\LogPing::handle
    - phpunit.test             SmokeTest::testPasses          Tests\Unit\SmokeTest::testPasses
    …
```

---

## Detector matrix

| kind                 | source (runtime / static)                                                                                       | notes                                              |
| ---                  | ---                                                                                                             | ---                                                |
| `laravel.route`      | `app('router')->getRoutes()`                                                                                    | covers attribute / closure / `Route::resource()`   |
| `laravel.command`    | `Artisan::all()` (vendor commands filtered)                                                                     | handler = `handle()`                               |
| `laravel.job`        | filesystem walk `app/Jobs/` for `Illuminate\Contracts\Queue\ShouldQueue`                                        | handler = `handle()`                               |
| `laravel.listener`   | filesystem walk `app/Listeners/` for `handle()` or `__invoke`                                                   | matches Laravel auto-discovery convention          |
| `symfony.route`      | `RouterInterface::getRouteCollection()`                                                                         | covers attribute / YAML / XML / PHP-config         |
| `symfony.command`    | `Application::all()`, `LazyCommand` unwrapped, vendor filtered                                                  | handler = `execute()`                              |
| `phpunit.test`       | filesystem walk `tests/` for `PHPUnit\Framework\TestCase` subclasses                                            | matches `test*` methods or `#[Test]` attribute     |

### How watson reads routes

Multiple paths exist for "tell me which routes this app has." watson currently uses the first row in each framework column. The rest are noted so you understand the trade-off and what's on the roadmap.

| approach                                      | needs                                            | covers                                                | speed             | trade-off                                                                                                            | watson today |
| ---                                           | ---                                              | ---                                                   | ---               | ---                                                                                                                  | ---          |
| **Boot kernel + `Route::getRoutes()`** (Laravel) | working `composer install`, writable `bootstrap/cache` | everything wired (attribute, closure, `Route::resource`, package providers) | ~kernel boot      | runtime authoritative; piggybacks on Artisan that's already running                                                  | ✅ default    |
| **Boot kernel + `RouterInterface`** (Symfony)    | working `composer install` + `var/cache` writable | everything (attribute, YAML, XML, PHP-config, service-tag) | ~kernel boot      | runtime authoritative; piggybacks on the console kernel                                                              | ✅ default    |
| **Read compiled container** (Symfony)            | `cache:warmup` ran                              | same as kernel-boot — Symfony serialises the full route map into PHP arrays | very fast (~ms)   | skip the kernel boot entirely; risk: stale cache between `cache:warmup` runs                                          | v0.4 candidate |
| **Read compiled route cache** (Laravel)          | `php artisan route:cache` ran                   | every cached route                                     | very fast         | same speed/staleness story; many teams don't run `route:cache` outside production                                     | v0.4 candidate |
| **Shell `bin/console debug:router --format=json`** | working install + `.env`                       | runtime authoritative                                 | slow (2 PHP procs)| no in-process API needed; useful when watson can't be installed as a dep                                              | not used     |
| **Shell `php artisan route:list --json`**        | same                                             | runtime authoritative                                 | slow              | same                                                                                                                 | not used     |
| **AST-only attribute scan**                      | source files                                    | only attribute-declared routes                        | very fast         | misses YAML / XML / service-tag / package-provided routes — opaque to a static reader                                | v0.4 fallback |

In one line: **watson asks the framework**. Neither AST guessing nor shell-out gives the same fidelity as in-process kernel introspection, and the kernel is already running because watson lives inside the app's own console binary.

### Deferred to v0.4

Compiled-container fast path for Symfony (`var/cache/<env>/url_matching_routes.php`), compiled-route fast path for Laravel (`bootstrap/cache/routes-v7.php`), Symfony messenger handlers / event subscribers, Laravel scheduled tasks, Mailables / Notifications / Broadcast channels, AST-based static fallback for projects whose kernel can't boot, optional PHPStan-driven type-aware reach, two-commit non-HEAD diffs via `git worktree add`.

---

## Output schema

```json
{
  "tool": "watson",
  "version": "0.3.0",
  "language": "php",
  "framework": "laravel",
  "context": {"root": "/abs/path", "base": "main", "head": "<working tree>"},
  "analyses": [
    {
      "name": "blastradius",
      "version": "0.3.0",
      "ok": true,
      "result": {
        "summary": {"files_changed": 1, "entry_points_affected": 2},
        "affected_entry_points": [
          {
            "kind": "laravel.route",
            "name": "users.show",
            "handler": {"fqn": "App\\Controller::show", "path": "app/Controller.php", "line": 42},
            "extra": {"path": "/users/{id}", "methods": ["GET"]},
            "min_confidence": "NameOnly"
          }
        ]
      }
    }
  ]
}
```

The envelope is **multi-analysis**: each command pushes one block onto `analyses[]`. Future analyses (e.g. test-impact, schedule-fanout) drop in without breaking consumers.

### Reach algorithm

watson uses **file-level reach**: an entry point is "affected" iff its handler file appears in the diff. High recall, modest precision (a docblock-only edit shows up). Confidence is reported as `NameOnly` so consumers can filter accordingly. Production traffic on Laravel codebases with heavy interface-DI showed file-level is the only signal that holds up; method-level call-graph reach is deferred to a future release behind an opt-in PHPStan flag.

---

## Recipes

```bash
# Pre-push gut check
php artisan watson:blastradius

# Staged-only review brief
php bin/console watson:blastradius --cached --format=md | pbcopy

# PR-style merge-base diff (matches GitHub's "Files changed" view)
php artisan watson:blastradius origin/main...HEAD --format=md

# Pipe into an LLM reviewer
php artisan watson:blastradius main..HEAD --format=md | llm \
  --system "Review this PR. Focus on the affected entry points."

# Tight CI loop — routes only, no filesystem walk
php artisan watson:blastradius --scope=routes --format=json
```

---

## Repository layout

```
watson/
├── src/
│   ├── Core/              # framework-neutral primitives
│   ├── Laravel/           # Artisan commands + collectors + ServiceProvider
│   └── Symfony/           # console commands + collectors + Bundle
├── tests/                 # PHPUnit unit tests
├── features/              # Behat scenarios + step definitions
├── fixtures/
│   ├── laravel-app/       # hermetic Laravel 11 app
│   └── symfony-app/       # hermetic Symfony 7 micro-kernel
└── .github/workflows/     # CI
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for dev setup and the recipe for adding a new detector. See [CHANGELOG.md](CHANGELOG.md) for release notes.

---

## License

MIT. See [LICENSE](LICENSE).
