# Contributing

`watson` is a Composer monorepo: three packages under `packages/`, two
hermetic fixture apps under `fixtures/`, Behat scenarios at the root.

## Setup

```bash
git clone git@github.com:HellPat/watson.git
cd watson
composer install                          # pulls all three packages via path repos
composer install -d fixtures/laravel-app  # fixture deps for Behat (laravel/framework, phpunit)
composer install -d fixtures/symfony-app  # fixture deps for Behat (symfony/framework-bundle)
```

## Run tests

```bash
composer ci                # runs PHPUnit + Behat
vendor/bin/phpunit         # unit tests against watson/core only
vendor/bin/behat           # 6 hermetic scenarios (no real-app)
WATSON_EASY_PLU_ROOT=~/easy-plu/backend vendor/bin/behat --tags=smoke
```

## Adding a detector

Each adapter has a `Runtime/` namespace. Pattern:

1. Add a collector class under `packages/<adapter>/src/Runtime/<Kind>Collector.php`.
2. Wire it into `Runtime/Collector::collect()` (the `--scope=all` branch).
3. Add a fixture artefact under `fixtures/<adapter>-app/` so Behat can verify it appears.
4. Add a kind to the stable order in `packages/core/src/Output/Renderer.php::groupByKind()`.
5. Add a Behat assertion line: `Then the JSON output contains entry points of kind "<kind>"`.

## Coding standards

- PHP 8.2+, `declare(strict_types=1);` mandatory.
- `final` by default; only un-final when there's a documented extension point.
- Constructor property promotion + readonly properties for value objects.
- No mocks in tests — exercise real plumbing where reasonable (the `DiffSpec` test uses a real tempdir git repo).
- One job per class. Pure where possible.

## Commit style

Conventional-style subjects but free-form bodies. Each phase / feature
lands as its own commit on a topic branch, then merges into `main` via
`--no-ff` so the history shows the rollup.

## Releasing

1. Bump `Envelope::TOOL_VERSION` and `Analysis\Blastradius::VERSION` in `watson/core`.
2. Update `CHANGELOG.md` — move `[Unreleased]` to `[X.Y.Z] — YYYY-MM-DD`.
3. Tag: `git tag -a vX.Y.Z -m "vX.Y.Z"`.
4. Push: `git push origin main vX.Y.Z`.
5. Packagist auto-pulls all three packages from the new tag.
