# watson

PR blast-radius analyzer for PHP. Tells a code reviewer (human or AI) which application entry points the diff actually reaches — by parsing the codebase with [mago](https://github.com/carthage-software/mago), running mago's static analyzer to populate a reverse call graph, intersecting `git diff` with the symbol map, and reporting routes / commands / jobs / listeners / scheduled tasks that the change can be observed from.

Supports Symfony and Laravel. Auto-detects which one from `composer.json` + the `bin/console` / `artisan` markers.

```bash
# In any PHP project directory:
watson blastradius                 # working tree vs HEAD
watson blastradius --cached        # staged index vs HEAD
watson blastradius main..HEAD      # main branch vs HEAD
watson blastradius origin/main...HEAD --format md | pbcopy   # PR-style review brief
```

---

## Install

`watson` is a Rust binary. From a clone of this repo:

```bash
cargo build --release
ln -sf "$PWD/target/release/watson" /usr/local/bin/watson
```

Requires Rust 1.95+ (mago needs it). `git` is the only runtime dependency.

---

## Commands

### `watson blastradius`

Report entry points whose handlers transitively reach changed code.

```
watson blastradius [<rev>[..<rev2>|...<rev2>]] [--cached] [--root <path>] [--format json|md|text]
```

#### CLI shapes — `git diff` semantics

| Form | Meaning |
| --- | --- |
| `(no args)` | working tree vs HEAD |
| `--cached` / `--staged` | index vs HEAD |
| `<rev>` | working tree vs `<rev>` |
| `<a> <b>` | `<a>` vs `<b>` |
| `<a>..<b>` | same as `<a> <b>` |
| `<a>...<b>` | merge-base(`<a>`, `<b>`) vs `<b>` (PR-style review diff) |

Reference: <https://git-scm.com/docs/git-diff>

#### What watson detects

##### Symfony

| kind | source |
| --- | --- |
| `symfony.route` | `#[Route(...)]` (both `Attribute` and `Annotation` namespaces) on a method or class |
| `symfony.command` | `#[AsCommand(...)]`; classes extending `Symfony\Component\Console\Command\Command` with a literal `protected static $defaultName` |
| `symfony.message_handler` | `#[AsMessageHandler]`; classes implementing `Symfony\Component\Messenger\Handler\MessageHandlerInterface` |
| `symfony.event_listener` | `#[AsEventListener]`; classes implementing `Symfony\Component\EventDispatcher\EventSubscriberInterface` (handler = `getSubscribedEvents`; per-method extraction lands later) |
| `symfony.cron_task` | `#[AsCronTask('cron-expr', method?)]` on class or method |
| `symfony.periodic_task` | `#[AsPeriodicTask(frequency, method?)]` on class or method |
| `symfony.schedule_provider` | `#[AsSchedule]`; classes implementing `Symfony\Component\Scheduler\ScheduleProviderInterface` |

##### Laravel (v0.2 core)

| kind | source |
| --- | --- |
| `laravel.route` | `Route::get/post/put/patch/delete/options/match/any` in `routes/*.php` (handlers as `[Class::class, 'method']`, `'Class@method'`, `Class::class`, or closure); `Route::resource` / `apiResource` / `singleton` / `apiSingleton`; `Route::redirect` / `permanentRedirect` / `view` |
| `laravel.command` | classes extending `Illuminate\Console\Command` with `protected $signature = '...'`; closure commands via `Artisan::command(...)` in `routes/console.php` |
| `laravel.job` | classes implementing `Illuminate\Contracts\Queue\ShouldQueue` or `ShouldQueueAfterCommit` (handler = `handle()`) |
| `laravel.listener` | classes under `App\Listeners\*` with a `handle(EventClass)` method (auto-discovery convention); event class FQN recorded in `extra.event` |
| `laravel.scheduled_task` | `Schedule::command(...)` / `Schedule::job(...)` / `Schedule::call(...)` in `routes/console.php` and `app/Console/Kernel::schedule()` |

##### Known gaps

- **Symfony YAML / XML / PHP-config routes parsed directly** — non-goal. Run `bin/console cache:warmup` to populate `var/cache/<env>/url_matching_routes.php` (compiled-cache loader is in progress) or pass `--use-bin-console` (planned) to shell out at run time.
- **Symfony service-tag entry points** without a marker attribute / interface — same workaround.
- **Laravel mailables, notifications, broadcast channels** — deferred to v0.3.
- **Laravel listeners outside `App\Listeners\*`** — out of scope; auto-discovery is the convention.
- **Two-commit diffs where head ≠ HEAD/working-tree** — error rather than silent miscounts (watson reads symbol locations from on-disk files). Future fix uses `git worktree add`.

#### Output formats

- `--format json` *(default)* — multi-analysis envelope, machine-readable. Schema:
  ```json
  {
    "tool": "watson", "version": "...", "language": "php",
    "framework": "symfony" | "laravel",
    "context": { "root": "...", "base": "...", "head": "..." },
    "analyses": [
      { "name": "blastradius", "version": "...", "ok": true, "result": {
        "summary": { "files_changed": N, "symbols_changed": N, "entry_points_affected": N },
        "changed_symbols": [ { "fqn": "...", "path": "...", "line_start": N, "line_end": N,
                               "affects": [ { "kind": "...", "name": "...", "ep_index": N } ] } ],
        "affected_entry_points": [ { "kind": "...", "name": "...", "handler": {...},
                                     "extra": {...}, "witness_path": [...], "min_confidence": "..." } ]
      } }
    ]
  }
  ```
- `--format md` — for PR descriptions and AI reviewers. Per-kind sections, witness paths in fenced text blocks, `affects: ...` line under each changed-symbol bullet.
- `--format text` — plain ASCII for terminals. Stable per-kind order, no decorations.

#### Examples

```bash
# Pre-commit gut check — what handlers does my uncommitted edit reach?
watson blastradius

# Review the staged index without including unstaged edits
watson blastradius --cached --format md

# Compare against main
watson blastradius main..HEAD

# PR-style merge-base diff (matches GitHub's "Files changed" view)
watson blastradius origin/main...HEAD --format md | pbcopy

# Run against another project
watson blastradius main..HEAD --root /path/to/symfony-app --format md > /tmp/pr.md
```

---

### `watson list-entrypoints`

```
watson list-entrypoints [--root <path>] [--format json|md|text]
```

Snapshot every entry point watson finds in the project. Useful as a debug aid (verify watson sees what you expect before relying on `blastradius`) and for documenting the runtime surface.

Output shape and format flags match `blastradius`.

---

## How it works

1. Walk the project root for `.php` files, skipping `.git`, `.worktrees`, `vendor`, `var`.
2. **Pass 1, in parallel** (rayon, per-thread `bumpalo` arena): parse each file with `mago-syntax`, resolve names with `mago-names`, walk the AST for entry-point declarations, run `mago-codex::scan_program` to extract per-file metadata.
3. Merge per-file `CodebaseMetadata`; run `mago-codex::populate_codebase` once to wire hierarchies and signature-level references.
4. **Pass 2, in parallel**: re-parse + run `mago-analyzer::Analyzer::analyze` on each file. Each thread accumulates a local `SymbolReferences`; we merge them after the parallel map.
5. Convert mago's `SymbolReferences::get_back_references()` (callee → callers) into the engine-neutral `CallEdge` list.
6. `git diff` between the resolved base and head. Parse `@@ -a,b +c,d @@` hunks to get changed line ranges per file.
7. Intersect changed line ranges with symbol line ranges (path-indexed; one canonicalize per unique path) → `ChangedSymbol[]`.
8. Reverse-BFS from each changed symbol through the call graph; record one shortest witness path per affected entry point, plus an inverse `affects_by_changed` mapping.
9. Project everything onto the JSON envelope; render Markdown / Text on top.

Performance on `~/easy-plu/backend` (real Laravel app, 4437 PHP files outside vendor): blastradius vs HEAD~10 is ~1.3s wall, list-entrypoints is ~0.9s wall.

---

## Validated against

`tests/real_apps.rs` runs `#[ignore]`d smoke tests against three real-world projects from local disk:

| Project | Framework | Files (non-vendor) | Recent diff result |
| --- | --- | --- | --- |
| team-up (Symfony) | Symfony 7 | 171 | 36 files, 124 symbols, 5 routes affected (admin Saisonrahmen) |
| project-l | Symfony 7 | ~395 | 16 files, 2 symbols, 0 affected (UI-only fix) |
| easy-plu/backend | Laravel 11 | 4,437 | 131 files, **165 symbols**, 3 affected (2 commands + 1 job) |

Run them locally:

```bash
cargo test --test real_apps -- --ignored --nocapture
# Override paths via env vars:
WATSON_TEAM_UP_ROOT=/path \
WATSON_PROJECT_L_ROOT=/path \
WATSON_EASY_PLU_ROOT=/path/backend \
  cargo test --test real_apps -- --ignored --nocapture
```

The smoke harness picks a recent commit pair from each repo's history that touches PHP, runs blastradius, and asserts the envelope shape + framework auto-detection. It does not assert exact numbers (those rotate with the real-app history).

---

## Honest caveats

- The reverse call graph is **approximate**. mago resolves what it can statically; runtime dispatch (interface picks at runtime, magic `__call`) is invisible. Output's `min_confidence` field is reserved for future per-edge confidence; all current edges are reported as `Confirmed` because mago either resolved them or didn't surface them at all.
- `mago` is one author + a young 1.x release line. We pin `=1.25.1` and only upgrade deliberately.
- This is a learning project. Code quality is honest, not polished.

License: MIT OR Apache-2.0. Same as the mago crates we depend on.
