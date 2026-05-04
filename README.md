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

---

## Post-release recipes

After a deploy, pipe the **just-shipped** entry points into an LLM that has observability MCP servers wired up — e.g. [Better Stack MCP](https://betterstack.com/docs/getting-started/integrations/mcp/) (`claude mcp add betterstack --transport http https://mcp.betterstack.com`). The LLM gets the surface that changed *and* live metrics — it can correlate the two.

### Latency regression on routes that changed in the last release

```bash
vendor/bin/watson blastradius v1.4.0..v1.5.0 --scope=routes --format=md | llm \
  --system "These routes shipped in v1.5.0. Use Better Stack MCP:
for each route, query p50 / p95 latency since the deploy timestamp
and compare to the previous 24h baseline. Flag any route whose p95
grew >20% or whose error rate doubled."
```

### Error rate / exception regression after deploy

```bash
vendor/bin/watson blastradius v1.4.0..v1.5.0 --format=md | llm \
  --system "These entry points (routes / commands / jobs / listeners)
shipped in v1.5.0. Use Better Stack MCP error tracking to:
- list new exception classes seen on any affected handler since deploy,
- count occurrences vs the prior 24h,
- group by handler FQN and rank by impact."
```

### Open incidents touching the changed surface

```bash
vendor/bin/watson list-entrypoints --scope=routes --format=md | llm \
  --system "Use Better Stack MCP to list all currently-open incidents.
For each incident, identify which (if any) of the entry points below
is involved. Output a markdown table mapping incident → affected
entry point with a one-line summary."
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

### `watson <cmd> --help`

```
$ watson blastradius --help

Description:
  Report which routes, commands, jobs, and listeners are reached by a git diff.

Usage:
  blastradius [options] [--] [<revisions>...]

Arguments:
  revisions              Git diff revisions: <rev>, <a> <b>, <a>..<b>, or <a>...<b> (merge-base).
                         Empty = working tree vs HEAD.

Options:
      --cached           Diff staged index vs HEAD instead of working tree
      --project=PROJECT  Project root (defaults to walking up from CWD)
      --format=FORMAT    Output format: text (human terminal), md (markdown for PRs/LLMs),
                         json (machine), tok (tab-separated, token-optimized for LLM pipes)
                         [default: "text"]
      --scope=SCOPE      routes (cheapest, runtime registry only) or all
                         (adds commands / jobs / listeners / tests) [default: "all"]
      --app-env=APP-ENV  APP_ENV passed to bin/console / artisan [default: "dev"]
```

```
$ watson list-entrypoints --help

Description:
  Snapshot every route, command, job, listener, and test the framework has registered.

Usage:
  list-entrypoints [options]

Options:
      --project=PROJECT  Project root (defaults to walking up from CWD)
      --format=FORMAT    Output format: text (human terminal), md (markdown for PRs/LLMs),
                         json (machine), tok (tab-separated, token-optimized for LLM pipes)
                         [default: "text"]
      --scope=SCOPE      routes (cheapest, runtime registry only) or all
                         (adds commands / jobs / listeners / tests) [default: "all"]
      --app-env=APP-ENV  APP_ENV passed to bin/console / artisan [default: "dev"]
```

### `--format=tok` — token-optimized for LLM pipes

Tab-separated, no JSON keys, no whitespace padding. Header lines start with `#`. Designed for piping into LLM prompts where every token costs money.

```
# watson 0.3.0 list-entrypoints php/laravel root=/abs/path
# entrypoints=4
# kinds: lc=laravel.command lj=laravel.job lr=laravel.route pt=phpunit.test
# fields: kind\tname\thandler\tpath:line\textra
lr	users.show	App\Http\Controllers\UserController::show	app/Http/Controllers/UserController.php:42	GET /users/{id}
lc	app:ping	App\Console\Commands\PingCommand::handle	app/Console/Commands/PingCommand.php:14
lj	App\Jobs\PingJob	App\Jobs\PingJob::handle	app/Jobs/PingJob.php:11
pt	SmokeTest::testPasses	Tests\Unit\SmokeTest::testPasses	tests/Unit/SmokeTest.php:11
```

Per-row layout: `kind \t name \t handler_fqn \t relative/path:line \t extra` (extra is HTTP-method+path for routes, empty otherwise).

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

## License

MIT. See [LICENSE](LICENSE).
