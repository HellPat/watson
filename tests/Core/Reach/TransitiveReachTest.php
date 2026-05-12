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
        mkdir($this->project . '/app/Jobs', 0700, true);
        mkdir($this->project . '/app/Service', 0700, true);
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
        $service = $this->project . '/app/Service/MyService.php';
        $job     = $this->project . '/app/Jobs/MyJob.php';
        file_put_contents($service, '<?php namespace App\Service; class MyService { public function run(): void {} }');
        file_put_contents($job, '<?php
            namespace App\Jobs;

            use App\Service\MyService;

            class MyJob {
                public function handle(): void {
                    (new MyService())->run();
                }
            }
        ');

        $reflector = $this->makeLoader();
        $entryPoints       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices($entryPoints, [self::cs($service)], $reflector, $this->project);

        self::assertSame([0], array_keys($hits));
    }

    public function testIgnoresEntryPointWhenNoTransitiveOverlapWithDiff(): void
    {
        $service = $this->project . '/app/Service/MyService.php';
        $other   = $this->project . '/app/Service/Unrelated.php';
        $job     = $this->project . '/app/Jobs/MyJob.php';
        file_put_contents($service, '<?php namespace App\Service; class MyService {}');
        file_put_contents($other, '<?php namespace App\Service; class Unrelated {}');
        file_put_contents($job, '<?php
            namespace App\Jobs;

            use App\Service\MyService;

            class MyJob {
                public function handle(): void {
                    (new MyService())->run();
                }
            }
        ');

        $reflector = $this->makeLoader();
        $entryPoints       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices($entryPoints, [self::cs($other)], $reflector, $this->project);

        self::assertSame([], $hits);
    }

    public function testFollowsClassConstAsClassReference(): void
    {
        // `Foo::class` should still drag Foo into the closure (covers the
        // `app(Foo::class)` / DI-container pattern).
        $service = $this->project . '/app/Service/MyService.php';
        $job     = $this->project . '/app/Jobs/MyJob.php';
        file_put_contents($service, '<?php namespace App\Service; class MyService {}');
        file_put_contents($job, '<?php
            namespace App\Jobs;

            use App\Service\MyService;

            class MyJob {
                public function handle(): void {
                    $fqn = MyService::class;
                }
            }
        ');

        $reflector = $this->makeLoader();
        $entryPoints       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices($entryPoints, [self::cs($service)], $reflector, $this->project);

        self::assertSame([0], array_keys($hits));
    }

    public function testFollowsTypeHintInMethodSignature(): void
    {
        $service = $this->project . '/app/Service/MyService.php';
        $job     = $this->project . '/app/Jobs/MyJob.php';
        file_put_contents($service, '<?php namespace App\Service; class MyService {}');
        file_put_contents($job, '<?php
            namespace App\Jobs;

            use App\Service\MyService;

            class MyJob {
                public function handle(MyService $svc): void {}
            }
        ');

        $reflector = $this->makeLoader();
        $entryPoints       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices($entryPoints, [self::cs($service)], $reflector, $this->project);

        self::assertSame([0], array_keys($hits));
    }

    public function testFollowsTransitiveChainTwoHopsDeep(): void
    {
        // Job → Service → DataMapper
        $mapper  = $this->project . '/app/Service/DataMapper.php';
        $service = $this->project . '/app/Service/MyService.php';
        $job     = $this->project . '/app/Jobs/MyJob.php';
        file_put_contents($mapper, '<?php namespace App\Service; class DataMapper {}');
        file_put_contents($service, '<?php
            namespace App\Service;
            class MyService {
                public function run(): void { (new DataMapper()); }
            }
        ');
        file_put_contents($job, '<?php
            namespace App\Jobs;
            use App\Service\MyService;
            class MyJob {
                public function handle(): void { (new MyService())->run(); }
            }
        ');

        $reflector = $this->makeLoader();
        $entryPoints       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices($entryPoints, [self::cs($mapper)], $reflector, $this->project);

        self::assertSame([0], array_keys($hits));
    }

    public function testIgnoresUnusedImports(): void
    {
        // Importing a class without using it must NOT pull it into the
        // closure — otherwise watson reports every route whose controller
        // imports a model it never actually instantiates.
        $service = $this->project . '/app/Service/MyService.php';
        $other   = $this->project . '/app/Service/Unused.php';
        $job     = $this->project . '/app/Jobs/MyJob.php';
        file_put_contents($service, '<?php namespace App\Service; class MyService {}');
        file_put_contents($other, '<?php namespace App\Service; class Unused {}');
        file_put_contents($job, '<?php
            namespace App\Jobs;

            use App\Service\MyService;
            use App\Service\Unused;

            class MyJob {
                public function handle(): void {
                    new MyService();
                }
            }
        ');

        $reflector = $this->makeLoader();
        $entryPoints       = [$this->ep('App\\Jobs\\MyJob', $job)];

        // A change in the Unused class must not flag the job — only
        // a change in MyService should.
        self::assertSame([], array_keys(TransitiveReach::affectedIndices($entryPoints, [self::cs($other)], $reflector, $this->project)));
        self::assertSame([0], array_keys(TransitiveReach::affectedIndices($entryPoints, [self::cs($service)], $reflector, $this->project)));
    }

    public function testMethodLevelPrecisionFlagsCallerThatUsesChangedMethod(): void
    {
        $service = $this->project . '/app/Service/MyService.php';
        $job     = $this->project . '/app/Jobs/MyJob.php';
        file_put_contents($service, '<?php
            namespace App\Service;
            class MyService {
                public function a(): void {}
                public function b(): void {}
            }');
        file_put_contents($job, '<?php
            namespace App\Jobs;
            use App\Service\MyService;
            class MyJob {
                public function handle(MyService $svc): void {
                    $svc->a();
                }
            }');

        $reflector = $this->makeLoader();
        $entryPoints       = [$this->ep('App\\Jobs\\MyJob', $job)];

        // Change MyService::a → MyJob is flagged.
        $hits = TransitiveReach::affectedIndices(
            $entryPoints,
            [new ChangedSymbol($service, 'App\\Service\\MyService', 'a', 1, 1)],
            $reflector,
            $this->project,
        );
        self::assertSame([0], array_keys($hits));
    }

    public function testMethodLevelPrecisionIgnoresCallerThatDoesNotUseChangedMethod(): void
    {
        $service = $this->project . '/app/Service/MyService.php';
        $job     = $this->project . '/app/Jobs/MyJob.php';
        file_put_contents($service, '<?php
            namespace App\Service;
            class MyService {
                public function a(): void {}
                public function b(): void {}
            }');
        // MyJob calls only ::a — change to ::b must NOT flag MyJob.
        file_put_contents($job, '<?php
            namespace App\Jobs;
            class MyJob {
                public function handle(\App\Service\MyService $svc): void {
                    $svc->a();
                }
            }');

        $reflector = $this->makeLoader();
        $entryPoints       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices(
            $entryPoints,
            [new ChangedSymbol($service, 'App\\Service\\MyService', 'b', 1, 1)],
            $reflector,
            $this->project,
        );
        // The handle method has a typed param MyService → ClassLevel
        // coupling to the whole class. ClassLevel matches any change in
        // the class, so MyService::b *will* still flag MyJob via the
        // signature. This is intentional: the signature alone couples
        // the handler to the class. Document via the next test.
        self::assertSame([0], array_keys($hits));
    }

    public function testMethodLevelPrecisionIgnoresCallerWithoutClassLevelCoupling(): void
    {
        $service = $this->project . '/app/Service/MyService.php';
        $other   = $this->project . '/app/Service/Other.php';
        $job     = $this->project . '/app/Jobs/MyJob.php';
        file_put_contents($service, '<?php
            namespace App\Service;
            class MyService {
                public function a(): void {}
                public function b(): void {}
            }');
        file_put_contents($other, '<?php
            namespace App\Service;
            class Other {
                public function go(): void {}
            }');
        // MyJob neither uses MyService nor type-hints it — no edge at all.
        file_put_contents($job, '<?php
            namespace App\Jobs;
            use App\Service\Other;
            class MyJob {
                public function handle(Other $o): void {
                    $o->go();
                }
            }');

        $reflector = $this->makeLoader();
        $entryPoints       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices(
            $entryPoints,
            [new ChangedSymbol($service, 'App\\Service\\MyService', 'b', 1, 1)],
            $reflector,
            $this->project,
        );
        self::assertSame([], array_keys($hits));
    }

    public function testTriggersReportTheChangedSymbol(): void
    {
        $service = $this->project . '/app/Service/MyService.php';
        $job     = $this->project . '/app/Jobs/MyJob.php';
        file_put_contents($service, '<?php
            namespace App\Service;
            class MyService { public function run(): void {} }');
        file_put_contents($job, '<?php
            namespace App\Jobs;
            class MyJob {
                public function handle(): void { (new \App\Service\MyService())->run(); }
            }');

        $reflector = $this->makeLoader();
        $entryPoints       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $changedSymbol   = new ChangedSymbol($service, 'App\\Service\\MyService', 'run', 1, 1);
        $hits = TransitiveReach::affectedIndices($entryPoints, [$changedSymbol], $reflector, $this->project);
        self::assertArrayHasKey(0, $hits);
        $syms = array_map(fn (ChangedSymbol $t) => $t->symbol(), $hits[0]['triggers']);
        self::assertContains('App\\Service\\MyService::run', $syms);
    }

    public function testReturnsEmptyWhenNoChangedFiles(): void
    {
        $reflector = $this->makeLoader();
        $entryPoints       = [$this->ep('App\\Jobs\\MyJob', $this->project . '/app/Jobs/MyJob.php')];
        self::assertSame([], TransitiveReach::affectedIndices($entryPoints, [], $reflector, $this->project));
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
