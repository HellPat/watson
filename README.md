# watson

> PR blast-radius analyzer for PHP. Standalone dev-only CLI that introspects Symfony / Laravel apps from the outside. Reports which routes, commands, jobs, listeners, and tests a git diff actually reaches.

[![ci](https://github.com/HellPat/watson/actions/workflows/ci.yml/badge.svg)](https://github.com/HellPat/watson/actions/workflows/ci.yml)
[![packagist](https://img.shields.io/packagist/v/hellpat/watson.svg)](https://packagist.org/packages/hellpat/watson)
[![license](https://img.shields.io/github/license/HellPat/watson.svg)](LICENSE)

```bash
composer require --dev hellpat/watson
vendor/bin/watson blastradius origin/main...HEAD --format=md
```

---

## LLM-pipe recipes

watson's markdown / JSON envelope drops straight into any LLM CLI. Each recipe pipes the `blastradius` (or `list-entrypoints`) envelope into a focused review prompt.

### Auto-review focused on what changed

```bash
vendor/bin/watson blastradius origin/main...HEAD --format=md | llm \
  --system "Review this PR. Focus only on the affected entry points listed below.
Flag anything risky around auth, money handling, or user-visible behaviour."
```

### Generate a manual testing guide

```bash
vendor/bin/watson blastradius origin/main...HEAD --format=md | llm \
  --system "You are a senior dev. Given these affected entry points, write a
concise manual testing guide: list the scenarios a reviewer must click through,
the edge cases most likely to break, and any data shape that needs verifying."
```

### Coverage gap check — is the change covered by e2e / feature tests?

```bash
vendor/bin/watson blastradius origin/main...HEAD --scope=all --format=json | llm \
  --system "The JSON contains affected entry points (routes / commands / jobs /
listeners) AND every phpunit.test in the repo. Cross-reference: which affected
entry points have at least one test that exercises them, and which don't?
Output a markdown table; flag gaps as 'NEEDS COVERAGE'."
```

### Tight CI loop — routes only

```bash
vendor/bin/watson blastradius origin/main...HEAD --scope=routes --format=md | llm \
  --system "Summarise which user-facing routes change in this PR. One line each."
```

### Risk-rank the change

```bash
vendor/bin/watson blastradius origin/main...HEAD --format=md | llm \
  --system "Rate this PR's risk (low / med / high) and explain in 3 bullets.
Consider: blast radius across kinds, whether async paths (jobs / listeners)
are involved, whether a test exists for every affected route."
```

### Release-note bullet

```bash
vendor/bin/watson blastradius origin/main...HEAD --format=md | llm \
  --system "Compress the affected routes / commands / jobs into one
user-facing CHANGELOG bullet."
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

| kind                          | source                                                                                  |
| ---                           | ---                                                                                     |
| `symfony.route`               | `bin/console debug:router --format=json`                                                |
| `symfony.command`             | `bin/console debug:container --tag=console.command --format=json` (vendor filtered)     |
| `laravel.route`               | `php artisan route:list --json`                                                         |
| `laravel.command`             | inline `php -r` runner that boots Laravel and dumps `Artisan::all()` (vendor filtered)  |
| `laravel.job`                 | AST scan of `app/Jobs/` for `ShouldQueue` implementers                                  |
| `laravel.listener`            | AST scan of `app/Listeners/` for `handle()` / `__invoke()`                              |
| `phpunit.test`                | AST scan of `tests/` for `PHPUnit\Framework\TestCase` subclasses                        |

watson is a CLI binary, not a bundle/provider. AST scans go through [`roave/better-reflection`](https://github.com/Roave/BetterReflection) — watson never `require_once`s your app's source.

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

## License

MIT. See [LICENSE](LICENSE).
