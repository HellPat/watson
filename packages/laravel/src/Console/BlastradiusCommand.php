<?php

declare(strict_types=1);

namespace Watson\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Routing\Router;
use Watson\Core\Analysis\Blastradius;
use Watson\Core\Diff\DiffSpec;
use Watson\Core\Output\Envelope;
use Watson\Core\Output\Renderer;
use Watson\Laravel\Runtime\RouteCollector;

/**
 * `php artisan watson:blastradius [revision-spec]`. Resolves a `git diff`
 * shaped revision argument, asks Laravel for its runtime route + command
 * registry, intersects with the diff, and emits the blastradius envelope.
 *
 * Default behaviour mirrors the Rust build:
 *   (no args)        working tree vs HEAD
 *   --cached         index vs HEAD
 *   <rev>            working tree vs <rev>
 *   <a> <b>          <a> vs <b>
 *   <a>..<b>         same as `<a> <b>`
 *   <a>...<b>        merge-base(a, b) vs <b>
 */
final class BlastradiusCommand extends Command
{
    protected $signature = 'watson:blastradius
        {revisions?* : git diff revision spec(s) — `<a>..<b>`, `<a>...<b>`, or two args}
        {--cached : compare the staged index against HEAD instead of the working tree}
        {--format=json : Output format (json|md|text)}';

    protected $description = 'Report entry points whose handler files are in the diff.';

    public function handle(Router $router, ConsoleKernel $consoleKernel): int
    {
        $repoPath = base_path();
        /** @var list<string> $revisions */
        $revisions = (array) $this->argument('revisions');
        $spec = DiffSpec::resolve($repoPath, $revisions, (bool) $this->option('cached'));

        $envelope = new Envelope(
            language: 'php',
            framework: 'laravel',
            rootPath: $repoPath,
            base: $spec->baseDisplay,
            head: $spec->headDisplay,
        );

        Blastradius::run(
            $envelope,
            $repoPath,
            $spec,
            RouteCollector::collect($router, $consoleKernel),
        );

        $this->output->write(Renderer::render($this->option('format'), $envelope));

        return self::SUCCESS;
    }
}
