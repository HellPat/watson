# watson

> PR blast-radius analyzer for PHP. Standalone dev-only CLI that introspects Symfony / Laravel apps from the outside. Reports which routes, commands, jobs, message handlers, and tests a diff actually reaches.

[![ci](https://github.com/HellPat/watson/actions/workflows/ci.yml/badge.svg)](https://github.com/HellPat/watson/actions/workflows/ci.yml)
[![packagist](https://img.shields.io/packagist/v/hellpat/watson.svg)](https://packagist.org/packages/hellpat/watson)
[![license](https://img.shields.io/github/license/HellPat/watson.svg)](LICENSE)

```bash
composer require --dev hellpat/watson
git diff --name-only origin/main...HEAD | vendor/bin/watson blastradius --format=md
```

watson does not shell out to git. You pipe a file list (or a unified diff) in, watson tells you which framework entry points are reached. Works with `git diff`, `svn diff`, GitHub-Action diff payloads, or a hand-curated list.

---

## Recipes

Each block below is a description followed by the command. All examples assume `composer require --dev hellpat/watson` is done.

### Pre-merge — review prompts piped to an LLM

```bash
# 1. Auto-review focused only on what changed
#    LLM is told the affected entry points; flags risky areas.
git diff --name-only origin/main...HEAD | vendor/bin/watson blastradius --format=md | llm \
  --system "Review this PR. Focus only on the affected entry points listed below.
Flag anything risky around auth, money handling, or user-visible behaviour."


# 2. Generate a manual testing guide
#    Turns the blast radius into a concrete click-through checklist for QA.
git diff --name-only origin/main...HEAD | vendor/bin/watson blastradius --format=md | llm \
  --system "You are a senior dev. Given these affected entry points, write a
concise manual testing guide: list the scenarios a reviewer must click through,
the edge cases most likely to break, and any data shape that needs verifying."


# 3. Coverage gap check — is the change covered by e2e / feature tests?
#    `--scope=all` includes phpunit.test entries so the LLM can cross-reference.
git diff --name-only origin/main...HEAD | vendor/bin/watson blastradius --scope=all --format=json | llm \
  --system "The JSON contains affected entry points (routes / commands / jobs /
message handlers) AND every phpunit.test in the repo. Cross-reference: which affected
entry points have at least one test that exercises them, and which don't?
Output a markdown table; flag gaps as 'NEEDS COVERAGE'."


# 4. Tight CI loop — routes only, one-line summary
#    `--scope=routes` skips the messenger / jobs / tests scans.
git diff --name-only origin/main...HEAD | vendor/bin/watson blastradius --scope=routes --format=md | llm \
  --system "Summarise which user-facing routes change in this PR. One line each."


# 5. Risk-rank the change
#    Same input as (1), different rubric.
git diff --name-only origin/main...HEAD | vendor/bin/watson blastradius --format=md | llm \
  --system "Rate this PR's risk (low / med / high) and explain in 3 bullets.
Consider: blast radius across kinds, whether async paths (jobs / message
handlers) are involved, whether a test exists for every affected route."
```

### Post-release — observability MCP correlation

After a deploy, pipe the **just-shipped** entry points into an LLM that has an observability MCP server wired up — e.g. [Better Stack MCP](https://betterstack.com/docs/getting-started/integrations/mcp/) (`claude mcp add betterstack --transport http https://mcp.betterstack.com`). The LLM gets the surface that changed *and* live metrics — it can correlate the two without you copy-pasting route names into a dashboard.

```bash
# 1. Latency regression on routes that shipped in the last release
#    Diffs two release tags so you only ask about routes that actually changed.
git diff --name-only v1.4.0..v1.5.0 | vendor/bin/watson blastradius --scope=routes --format=md \
  --base=v1.4.0 --head=v1.5.0 | llm \
  --system "These routes shipped in v1.5.0. Use Better Stack MCP:
for each route, query p50 / p95 latency since the deploy timestamp
and compare to the previous 24h baseline. Flag any route whose p95
grew >20% or whose error rate doubled."


# 2. Error / exception regression after deploy
#    Wider scope so jobs and message handlers are also checked for new exceptions.
git diff --name-only v1.4.0..v1.5.0 | vendor/bin/watson blastradius --format=md \
  --base=v1.4.0 --head=v1.5.0 | llm \
  --system "These entry points (routes / commands / jobs / message handlers)
shipped in v1.5.0. Use Better Stack MCP error tracking to:
- list new exception classes seen on any affected handler since deploy,
- count occurrences vs the prior 24h,
- group by handler FQN and rank by impact."


# 3. Open incidents touching the changed surface
#    Uses list-entrypoints (the full registry) since incidents may not align
#    with a specific release window.
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
```

No bundle, no service provider, no `config/bundles.php` entry. watson auto-detects Symfony vs Laravel by walking up from CWD looking for `bin/console` or `artisan`.

**Requirements:** PHP 8.4+. Symfony 6.4 / 7.x / 8.x or Laravel 10 / 11 / 12. `git` is *not* a watson dependency — watson only reads the diff you pipe in. If your diff source is git, you'll have it for that reason.

---

## Commands

### `watson blastradius`

Reads a list of changed files from stdin (or `--files=`) and reports which entry points reach them. watson does not run git; the caller is responsible for picking the diff source.

| input shape                                                              | when to use                                                            |
| ---                                                                      | ---                                                                    |
| `git diff --name-only <a>...<b> \| watson blastradius`                    | most common — pipe `git diff --name-only` output as one path per line  |
| `git diff <a>...<b> \| watson blastradius --unified-diff`                 | full unified diff on stdin; watson extracts post-image filenames       |
| `watson blastradius --files=path/a --files=path/b`                       | no git involved — pre-computed list, GitHub-Action payload, etc.       |
| `git diff --cached --name-only \| watson blastradius`                     | staged-only review — `--cached` lives on the caller's `git`, not watson |

When run in an interactive shell with no input piped and no `--files`, watson exits with a usage hint instead of silently producing zero results.

### `watson list-entrypoints`

Snapshot every entry point the framework has registered: routes, commands, message handlers, jobs (Laravel), tests. Same options as `blastradius`, minus the diff-input flags.

### `watson <cmd> --help`

```
$ watson blastradius --help

Description:
  Report which routes, commands, jobs, and message handlers are reached by
  a list of changed files (read from stdin, or --files=).

Usage:
  blastradius [options]

Options:
      --files=FILES      Explicit file path (repeatable, or comma-separated).
                         Bypasses stdin. (multiple values allowed)
      --unified-diff     Parse stdin as a unified diff (e.g. `git diff …`) instead
                         of a newline-separated path list.
      --base=BASE        Cosmetic label shown as the diff base in rendered output
      --head=HEAD        Cosmetic label shown as the diff head in rendered output
      --project=PROJECT  Project root (defaults to walking up from CWD)
      --format=FORMAT    text (human terminal) | md (PRs/LLMs) | json (machine)
                         | tok (token-optimized for LLM pipes) [default: "text"]
      --scope=SCOPE      routes (cheapest) | all (+ commands / jobs / message
                         handlers / tests) [default: "all"]
      --app-env=APP-ENV  APP_ENV passed to bin/console / artisan [default: "dev"]
```

```
$ watson list-entrypoints --help

Description:
  Snapshot every route, command, job, message handler, and test the framework
  has registered.

Usage:
  list-entrypoints [options]

Options:
      --project=PROJECT  Project root (defaults to walking up from CWD)
      --format=FORMAT    text (human terminal) | md (PRs/LLMs) | json (machine)
                         | tok (token-optimized for LLM pipes) [default: "text"]
      --scope=SCOPE      routes (cheapest) | all (+ commands / jobs / message
                         handlers / tests) [default: "all"]
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

| kind                       | source                                                                                                |
| ---                        | ---                                                                                                   |
| `symfony.route`            | `bin/console debug:router --format=json`                                                              |
| `symfony.command`          | `bin/console debug:container --tag=console.command --format=json` (vendor filtered)                   |
| `symfony.message_handler`  | `bin/console debug:container --tag=messenger.message_handler --format=json` (vendor filtered, message inferred via reflection on the handler's first param when the tag's `handles` is null) |
| `laravel.route`            | `php artisan route:list --json`                                                                       |
| `laravel.command`          | inline `php -r` runner that boots Laravel and dumps `Artisan::all()` (vendor filtered)                |
| `laravel.job`              | AST scan of `app/Jobs/` for `ShouldQueue` implementers                                                |
| `phpunit.test`             | AST scan of `tests/` for `PHPUnit\Framework\TestCase` subclasses                                      |

watson is a CLI binary, not a bundle/provider. AST scans go through [`roave/better-reflection`](https://github.com/Roave/BetterReflection) — watson never `require_once`s your app's source.

---

## License

MIT. See [LICENSE](LICENSE).
