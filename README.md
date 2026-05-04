# watson

> PR blast-radius analyzer for PHP. Standalone dev-only CLI that introspects Symfony / Laravel apps from the outside. Reports which routes, commands, jobs, listeners, and tests a diff actually reaches.

[![ci](https://github.com/HellPat/watson/actions/workflows/ci.yml/badge.svg)](https://github.com/HellPat/watson/actions/workflows/ci.yml)
[![packagist](https://img.shields.io/packagist/v/hellpat/watson.svg)](https://packagist.org/packages/hellpat/watson)
[![license](https://img.shields.io/github/license/HellPat/watson.svg)](LICENSE)

```bash
$ vendor/bin/watson blastradius main..HEAD --format=md
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

#### laravel.job (1)
##### App\Jobs\NotifyUserJob
- **Handler**: `App\Jobs\NotifyUserJob::handle` (`app/Jobs/NotifyUserJob.php:18`)
```

---

## Install

```bash
composer require --dev hellpat/watson
vendor/bin/watson list-entrypoints
vendor/bin/watson blastradius origin/main...HEAD --format=md
```

No bundle, no service provider, no `config/bundles.php` entry. watson auto-detects Symfony vs Laravel by walking up from CWD looking for `bin/console` or `artisan`.

**Requirements:** PHP 8.4+, git on `$PATH`. Symfony 6.4 / 7.x / 8.x or Laravel 10 / 11 / 12.

---

## Commands

### `watson blastradius`

| invocation                       | meaning                          |
| ---                              | ---                              |
| `watson blastradius`             | working tree vs HEAD             |
| `watson blastradius --cached`    | staged index vs HEAD             |
| `watson blastradius <rev>`       | working tree vs `<rev>`          |
| `watson blastradius <a>..<b>`    | `<a>` vs `<b>`                   |
| `watson blastradius <a>...<b>`   | merge-base(`<a>`,`<b>`) vs `<b>` — matches GitHub's "Files changed" view |

### `watson list-entrypoints`

Snapshot every entry point the framework registered.

### Flags (both commands)

| flag                           | default | meaning                                                         |
| ---                            | ---     | ---                                                             |
| `--project=<path>`             | cwd     | project root (otherwise walked up from CWD)                     |
| `--framework=symfony\|laravel` | auto    | force when both `bin/console` and `artisan` exist               |
| `--app-env=<env>`              | `dev`   | env passed to `bin/console` / `artisan`                         |
| `--format=json\|md\|text`      | `json`  | output format                                                   |
| `--scope=routes\|all`          | `all`   | `all` adds commands / jobs / listeners / tests                  |

---

## How watson reads your app

| kind                          | source                                                                         |
| ---                           | ---                                                                            |
| `symfony.route`               | `bin/console debug:router --format=json` + Better Reflection for line numbers  |
| `symfony.command`             | `bin/console list --format=json` cross-checked against `#[AsCommand]` AST scan |
| `laravel.route`               | `php artisan route:list --json` + Better Reflection for line numbers           |
| `laravel.command`             | `php artisan list --format=json` cross-checked against `app/Console/Commands/` |
| `laravel.job`                 | AST scan of `app/Jobs/` for `ShouldQueue` implementers                         |
| `laravel.listener`            | AST scan of `app/Listeners/` for `handle()` / `__invoke()`                     |
| `phpunit.test`                | AST scan of `tests/` for `PHPUnit\Framework\TestCase` subclasses               |

watson is a CLI binary, not a bundle/provider. Reflection goes through [`roave/better-reflection`](https://github.com/Roave/BetterReflection) — watson never `require_once`s your app's source.

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

Reach is **file-level**: an entry point is "affected" iff its handler file is in the diff. High recall, modest precision (a docblock-only edit shows up); confidence is reported as `NameOnly` so consumers can filter.

---

## Recipes

```bash
# PR-style merge-base diff (matches GitHub's "Files changed" view)
vendor/bin/watson blastradius origin/main...HEAD --format=md

# Pipe into an LLM reviewer
vendor/bin/watson blastradius main..HEAD --format=md | llm \
  --system "Review this PR. Focus on the affected entry points."

# Tight CI loop — routes only
vendor/bin/watson blastradius --scope=routes --format=json
```

---

## License

MIT. See [LICENSE](LICENSE).
