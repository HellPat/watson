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

- `watson/core`   — framework-agnostic engine. AST walking via
                    `nikic/php-parser`, entry-point detectors that work
                    on raw PHP source (attribute scans, marker-interface
                    scans), git-diff plumbing, reverse-reach BFS, JSON
                    / Markdown / Text envelope. No knowledge of
                    Laravel or Symfony.
- `watson/laravel`— Laravel service provider + Artisan command. Boots
                    Laravel app, calls `Route::getRoutes()` /
                    `Artisan::all()` for runtime-authoritative entry
                    points, merges with core's static analysis.
                    Auto-registered via `extra.laravel.providers`.
- `watson/symfony`— Symfony bundle + console command. Same idea using
                    `RouterInterface::getRouteCollection()` and the
                    Console application.

User installs the framework adapter:

```
composer require --dev watson/laravel
# or
composer require --dev watson/symfony
```

Auto-discovery wires up the commands; no manual config.

## Packages: status

- [ ] `watson/core`
  - [ ] `Engine\AstWalker` — parse a file, collect class / method nodes.
  - [ ] `Engine\EntryPointDetector` — find Symfony/Laravel attributes,
        marker interfaces, PHPUnit tests.
  - [ ] `Engine\CallGraph` — build caller→callee edges via
        `nikic/php-parser` + a simple type-of-receiver tracker. (Where
        Rust used mago, here we either: (a) bottom-of-the-barrel name
        resolution, or (b) optionally consume PHPStan's analysis cache
        when available.)
  - [ ] `Diff\GitDiff` — shell `git diff --unified=0`, parse hunks.
  - [ ] `Diff\HunkIntersect` — overlap symbol line ranges with hunks.
  - [ ] `Reach\ReverseBfs` — same algorithm we already shipped in Rust.
  - [ ] `Output\Envelope` + `Output\Markdown` / `Output\Text` /
        `Output\Json`.
  - [ ] CLI binary `bin/watson` (delegates to either framework adapter
        if present; falls back to source-only mode otherwise).
- [ ] `watson/laravel`
  - [ ] `WatsonServiceProvider`
  - [ ] `Console\BlastradiusCommand`     (Artisan: `watson:blastradius`)
  - [ ] `Console\ListEntrypointsCommand` (Artisan: `watson:list-entrypoints`)
  - [ ] Pull runtime route table via `app('router')->getRoutes()`.
  - [ ] Pull queued-listener registrations via `app('events')`.
- [ ] `watson/symfony`
  - [ ] `WatsonBundle`
  - [ ] `Command\BlastradiusCommand`     (`watson:blastradius`)
  - [ ] `Command\ListEntrypointsCommand` (`watson:list-entrypoints`)
  - [ ] Pull runtime routes via `RouterInterface`.
  - [ ] Pull message handlers via `messenger.routable_message_bus`.

## Optional PHPStan integration

PHPStan would help the `watson/core` call-graph step in two ways:

1. **Type inference** — replace our hand-rolled receiver-type tracker
   with PHPStan's `Scope`/`Type` system to resolve
   `$this->repo->find()` when the property has a type hint or generic
   constraint.
2. **Reflection** — `PHPStan\Reflection\ReflectionProvider` is a
   battle-tested, version-aware reflection layer; better than booting
   `ReflectionClass` and hoping autoloaders work.

Plan: if `phpstan/phpstan` is in the user's `require-dev`, watson
opportunistically enriches the call graph with PHPStan's type info.
Otherwise core uses a simpler tracker that handles typed properties
and promoted-constructor args directly. Keeps watson installable
without forcing PHPStan as a hard dep.

## Tests: Behat

Behat is the de-facto Cucumber/BDD tool for PHP. Layout:

```
features/
  blastradius.feature          # Gherkin scenarios
  list-entrypoints.feature
  bootstrap/
    FeatureContext.php          # step definitions
fixtures/
  laravel-app/                  # minimal real Laravel app, kernel-bootable
  symfony-app/                  # minimal real Symfony app
```

Each scenario:

```gherkin
Feature: Blastradius reports affected Laravel routes
  Scenario: Service edit fans out to all routes that use it
    Given the Laravel fixture at "fixtures/laravel-app"
    And I edit "app/Services/Pinger.php"
    When I run `php artisan watson:blastradius main..HEAD`
    Then the JSON output should report 4 affected entry points
    And every entry point should have kind "laravel.route"
```

`vendor/bin/behat` runs it. Same harness covers both framework
adapters because the step definitions delegate to the framework's
`artisan` / `bin/console` binary.

PHPUnit covers `watson/core` unit tests.

## Drop Rust?

Yes. The Rust tree is preserved at `legacy-rust/` for reference. Once
the PHP packages reach feature parity (entry-point catalog, file-level
reach, multi-route dedup, verbosity tiers), `legacy-rust/` gets moved
to a `rust-archive` git tag and deleted from `main`.

Git plumbing stays in PHP — `Symfony\Component\Process` for shelling
`git`, plus a tiny diff-parser on top. The Rust `gix`/`git2` story
gave us nothing the shell-out doesn't.

## CLI surface (unchanged from the Rust build)

```
watson blastradius [<rev>[..<rev2>|...<rev2>]] [-v|-vv] [--strict]
                   [--cached] [--root <path>] [--format json|md|text]
watson list-entrypoints [--root <path>] [--format json|md|text]
```

Same `git diff` revision shapes (`..`, `...`, `--cached`, no-arg). Same
verbosity tiers. Same multi-analysis envelope shape. The user's CLI
muscle memory carries over.
