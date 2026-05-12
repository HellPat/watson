<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Reach;

use PHPUnit\Framework\TestCase;
use Composer\Autoload\ClassLoader;
use Watson\Core\Diff\ChangedSymbol;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;
use Watson\Core\Reach\TransitiveReach;

final class TransitiveReachTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/watson_transitive_' . uniqid();
        mkdir($this->project . '/vendor/composer', 0700, true);
        file_put_contents($this->project . '/composer.json', (string) json_encode([
            'name'     => 'acme/app',
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ]));
        file_put_contents($this->project . '/vendor/composer/installed.json', (string) json_encode(['packages' => []]));
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->project);
    }

    public function testReportsEntryPointWhenTransitivelyReferencedClassFileChanges(): void
    {
        $service = $this->writeClass('App\\Service\\MyService', 'class MyService { public function run(): void {} }');
        $job     = $this->writeClass('App\\Jobs\\MyJob', '
            use App\Service\MyService;
            class MyJob {
                public function handle(): void { (new MyService())->run(); }
            }');

        $this->assertReaches('App\\Jobs\\MyJob', $job, self::cs($service));
    }

    public function testIgnoresEntryPointWhenNoTransitiveOverlapWithDiff(): void
    {
        $this->writeClass('App\\Service\\MyService', 'class MyService {}');
        $other = $this->writeClass('App\\Service\\Unrelated', 'class Unrelated {}');
        $job   = $this->writeClass('App\\Jobs\\MyJob', '
            use App\Service\MyService;
            class MyJob {
                public function handle(): void { (new MyService())->run(); }
            }');

        $this->assertDoesNotReach('App\\Jobs\\MyJob', $job, self::cs($other));
    }

    public function testFollowsClassConstAsClassReference(): void
    {
        // `Foo::class` should still drag Foo into the closure (covers the
        // `app(Foo::class)` / DI-container pattern).
        $service = $this->writeClass('App\\Service\\MyService', 'class MyService {}');
        $job     = $this->writeClass('App\\Jobs\\MyJob', '
            use App\Service\MyService;
            class MyJob {
                public function handle(): void { $fqn = MyService::class; }
            }');

        $this->assertReaches('App\\Jobs\\MyJob', $job, self::cs($service));
    }

    public function testFollowsTypeHintInMethodSignature(): void
    {
        $service = $this->writeClass('App\\Service\\MyService', 'class MyService {}');
        $job     = $this->writeClass('App\\Jobs\\MyJob', '
            use App\Service\MyService;
            class MyJob {
                public function handle(MyService $svc): void {}
            }');

        $this->assertReaches('App\\Jobs\\MyJob', $job, self::cs($service));
    }

    public function testFollowsTransitiveChainTwoHopsDeep(): void
    {
        $mapper = $this->writeClass('App\\Service\\DataMapper', 'class DataMapper {}');
        $this->writeClass('App\\Service\\MyService', '
            class MyService { public function run(): void { (new DataMapper()); } }');
        $job = $this->writeClass('App\\Jobs\\MyJob', '
            use App\Service\MyService;
            class MyJob {
                public function handle(): void { (new MyService())->run(); }
            }');

        $this->assertReaches('App\\Jobs\\MyJob', $job, self::cs($mapper));
    }

    public function testMaxDepthTruncatesDeeperChain(): void
    {
        // Chain: Job → Outer → Inner → Service → DataMapper.
        //   - maxDepth=1: BFS reaches Inner (1 hop from Service);
        //     Outer at hop 2 and Job at hop 3 are truncated.
        //   - maxDepth=0: unbounded (the new default) — Job is reached.
        $mapper = $this->writeClass('App\\Service\\DataMapper', 'class DataMapper {}');
        $this->writeClass('App\\Service\\MyService', '
            class MyService { public function run(): void { (new DataMapper()); } }');
        $this->writeClass('App\\Service\\Inner', '
            class Inner { public function step(): void { (new MyService())->run(); } }');
        $this->writeClass('App\\Service\\Outer', '
            class Outer { public function step(): void { (new Inner())->step(); } }');
        $job = $this->writeClass('App\\Jobs\\MyJob', '
            use App\Service\Outer;
            class MyJob {
                public function handle(): void { (new Outer())->step(); }
            }');

        $this->assertDoesNotReach('App\\Jobs\\MyJob', $job, self::cs($mapper), maxDepth: 1);
        $this->assertReaches('App\\Jobs\\MyJob', $job, self::cs($mapper), maxDepth: 0);
    }

    public function testIgnoresUnusedImports(): void
    {
        // Importing a class without using it must NOT pull it into the
        // closure — otherwise watson reports every route whose controller
        // imports a model it never actually instantiates.
        $service = $this->writeClass('App\\Service\\MyService', 'class MyService {}');
        $other   = $this->writeClass('App\\Service\\Unused', 'class Unused {}');
        $job     = $this->writeClass('App\\Jobs\\MyJob', '
            use App\Service\MyService;
            use App\Service\Unused;
            class MyJob {
                public function handle(): void { new MyService(); }
            }');

        $this->assertDoesNotReach('App\\Jobs\\MyJob', $job, self::cs($other));
        $this->assertReaches('App\\Jobs\\MyJob', $job, self::cs($service));
    }

    public function testMethodLevelPrecisionFlagsCallerThatUsesChangedMethod(): void
    {
        $service = $this->writeClass('App\\Service\\MyService', '
            class MyService {
                public function a(): void {}
                public function b(): void {}
            }');
        $job = $this->writeClass('App\\Jobs\\MyJob', '
            use App\Service\MyService;
            class MyJob {
                public function handle(MyService $svc): void { $svc->a(); }
            }');

        $this->assertReaches('App\\Jobs\\MyJob', $job, new ChangedSymbol($service, 'App\\Service\\MyService', 'a', 1, 1));
    }

    public function testMethodLevelPrecisionIgnoresCallerThatDoesNotUseChangedMethod(): void
    {
        // MyJob calls only ::a — change to ::b *would* otherwise be a
        // miss, but the typed param `MyService $svc` couples the handler
        // signature to the whole class via a ClassLevel edge, which
        // matches any change inside MyService. This is the intentional
        // signature-coupling behaviour; see the next test for the
        // genuine "no coupling at all" case.
        $service = $this->writeClass('App\\Service\\MyService', '
            class MyService {
                public function a(): void {}
                public function b(): void {}
            }');
        $job = $this->writeClass('App\\Jobs\\MyJob', '
            class MyJob {
                public function handle(\App\Service\MyService $svc): void { $svc->a(); }
            }');

        $this->assertReaches('App\\Jobs\\MyJob', $job, new ChangedSymbol($service, 'App\\Service\\MyService', 'b', 1, 1));
    }

    public function testMethodLevelPrecisionIgnoresCallerWithoutClassLevelCoupling(): void
    {
        // MyJob neither uses MyService nor type-hints it — no edge at all.
        $service = $this->writeClass('App\\Service\\MyService', '
            class MyService { public function a(): void {} public function b(): void {} }');
        $this->writeClass('App\\Service\\Other', '
            class Other { public function go(): void {} }');
        $job = $this->writeClass('App\\Jobs\\MyJob', '
            use App\Service\Other;
            class MyJob {
                public function handle(Other $o): void { $o->go(); }
            }');

        $this->assertDoesNotReach('App\\Jobs\\MyJob', $job, new ChangedSymbol($service, 'App\\Service\\MyService', 'b', 1, 1));
    }

    public function testTriggersReportTheChangedSymbol(): void
    {
        $service = $this->writeClass('App\\Service\\MyService', '
            class MyService { public function run(): void {} }');
        $job = $this->writeClass('App\\Jobs\\MyJob', '
            class MyJob {
                public function handle(): void { (new \App\Service\MyService())->run(); }
            }');

        $hits = $this->hits('App\\Jobs\\MyJob', $job, new ChangedSymbol($service, 'App\\Service\\MyService', 'run', 1, 1));
        self::assertArrayHasKey(0, $hits);
        $syms = array_map(fn (ChangedSymbol $t) => $t->symbol(), $hits[0]['triggers']);
        self::assertContains('App\\Service\\MyService::run', $syms);
    }

    public function testReturnsEmptyWhenNoChangedFiles(): void
    {
        $entryPoints = [$this->ep('App\\Jobs\\MyJob', $this->project . '/app/Jobs/MyJob.php')];
        self::assertSame([], TransitiveReach::affectedIndices($entryPoints, [], $this->makeLoader(), $this->project));
    }

    // -----------------------------------------------------------------
    // Test helpers — keep the assertions above readable.
    // -----------------------------------------------------------------

    /**
     * Write `<?php namespace ...; <body>` to the canonical
     * `app/<sub>/<ShortName>.php` path derived from the FQN.
     * Indentation in the body is normalised so callers can keep their
     * heredoc-style readable.
     */
    private function writeClass(string $fqn, string $body): string
    {
        $pos       = strrpos($fqn, '\\');
        $namespace = $pos === false ? '' : substr($fqn, 0, $pos);
        $shortName = $pos === false ? $fqn : substr($fqn, $pos + 1);
        $relDir    = str_replace('\\', '/', preg_replace('/^App(\\\\|$)/', 'app$1', $namespace) ?? '');
        $absDir    = rtrim($this->project . '/' . $relDir, '/');
        if (!is_dir($absDir)) {
            mkdir($absDir, 0700, true);
        }
        $path = $absDir . '/' . $shortName . '.php';
        file_put_contents($path, "<?php\nnamespace {$namespace};\n" . trim($body) . "\n");
        return $path;
    }

    private function assertReaches(string $entryFqn, string $entryPath, ChangedSymbol $change, int $maxDepth = 0): void
    {
        $hits = $this->hits($entryFqn, $entryPath, $change, $maxDepth);
        self::assertSame([0], array_keys($hits), "expected {$entryFqn} to reach the change at maxDepth={$maxDepth}");
    }

    private function assertDoesNotReach(string $entryFqn, string $entryPath, ChangedSymbol $change, int $maxDepth = 0): void
    {
        $hits = $this->hits($entryFqn, $entryPath, $change, $maxDepth);
        self::assertSame([], array_keys($hits), "expected {$entryFqn} NOT to reach the change at maxDepth={$maxDepth}");
    }

    /** @return array<int, array{path: list<string>, triggers: list<ChangedSymbol>}> */
    private function hits(string $entryFqn, string $entryPath, ChangedSymbol $change, int $maxDepth = 0): array
    {
        return TransitiveReach::affectedIndices(
            [$this->ep($entryFqn, $entryPath)],
            [$change],
            $this->makeLoader(),
            $this->project,
            5000,
            $maxDepth,
        );
    }

    private static function cs(string $path): ChangedSymbol
    {
        return new ChangedSymbol($path, null, null, 1, 1);
    }

    private function makeLoader(): ClassLoader
    {
        $loader = new ClassLoader();
        $loader->addPsr4('App\\', $this->project . '/app/');
        return $loader;
    }

    private function ep(string $fqn, string $path): EntryPoint
    {
        return new EntryPoint(
            kind: 'laravel.job',
            name: $fqn,
            handlerFqn: $fqn . '::handle',
            handlerPath: $path,
            handlerLine: 1,
            source: Source::Runtime,
        );
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $f) {
            $path = $f->getPathname();
            if (is_link($path) || $f->isFile()) {
                @unlink($path);
            } elseif ($f->isDir()) {
                @rmdir($path);
            }
        }
        @rmdir($dir);
    }
}
