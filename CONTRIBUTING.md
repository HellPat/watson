# Contributing

`watson` is a single Composer package with two adapter shells. Layout:

```
src/
├── Core/      # framework-neutral primitives (Envelope, EntryPoint, DiffSpec, FileLevelReach, ClassScanner, PhpUnitCollector)
├── Laravel/   # Artisan commands + ServiceProvider + collectors (RouteCollector / JobCollector / ListenerCollector)
└── Symfony/   # Console commands + Bundle + collectors (RouteCollector — LazyCommand-aware)
tests/         # PHPUnit unit tests against Core
features/      # Behat scenarios + step definitions
fixtures/      # Hermetic Laravel + Symfony apps that Behat shells against
```

## Setup

```bash
git clone git@github.com:HellPat/watson.git
cd watson
composer install                              # workspace + dev deps
composer install -d fixtures/laravel-app      # fixture deps for Behat (laravel/framework, phpunit)
composer install -d fixtures/symfony-app      # fixture deps for Behat (symfony/framework-bundle)
```

Both fixtures install `hellpat/watson` via a single path repo pointing at the workspace root.

## Run tests

```bash
composer ci                # PHPUnit + Behat
vendor/bin/phpunit         # 21 unit tests against src/Core
vendor/bin/behat           # 6 hermetic scenarios across both fixtures
WATSON_EASY_PLU_ROOT=~/easy-plu/backend vendor/bin/behat --tags=smoke
```

## Adding a detector

Each adapter has a `Runtime/` namespace. Pattern:

1. Add a collector under `src/<Adapter>/Runtime/<Kind>Collector.php`.
2. Wire it into `Runtime/Collector::collect()` (the `--scope=all` branch).
3. Add a fixture artefact under `fixtures/<adapter>-app/` so Behat verifies it appears.
4. Add the kind to the stable order in `src/Core/Output/Renderer.php::groupByKind()`.
5. Add a Behat assertion: `Then the JSON output contains entry points of kind "<kind>"`.

## Coding standards

- PHP 8.2+, `declare(strict_types=1);` mandatory.
- `final` by default; only un-final when there's a documented extension point.
- Constructor property promotion + readonly properties for value objects.
- No mocks in tests — exercise real plumbing where reasonable (the `DiffSpec` test uses a real tempdir git repo).
- One job per class. Pure where possible.

## Commit style

Conventional-style subjects but free-form bodies. Each phase / feature lands as its own commit on a topic branch, then merges into `main` via `--no-ff` so history shows the rollup.

## Releasing

1. Bump `Envelope::TOOL_VERSION` and `Analysis\Blastradius::VERSION` in `src/Core`.
2. Bump `extra.branch-alias.dev-main` in `composer.json`.
3. Update `CHANGELOG.md` — move `[Unreleased]` into `[X.Y.Z] — YYYY-MM-DD`.
4. Tag: `git tag -a vX.Y.Z -m "vX.Y.Z"`.
5. Push: `git push origin main vX.Y.Z`.
6. Packagist auto-pulls from the new tag (webhook-driven).
