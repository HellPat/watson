<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Reach;

use PHPUnit\Framework\TestCase;
use Composer\Autoload\ClassLoader;
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
        $eps       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices($eps, [$service], $reflector, $this->project);

        self::assertSame([0], $hits);
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
        $eps       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices($eps, [$other], $reflector, $this->project);

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
        $eps       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices($eps, [$service], $reflector, $this->project);

        self::assertSame([0], $hits);
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
        $eps       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices($eps, [$service], $reflector, $this->project);

        self::assertSame([0], $hits);
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
        $eps       = [$this->ep('App\\Jobs\\MyJob', $job)];

        $hits = TransitiveReach::affectedIndices($eps, [$mapper], $reflector, $this->project);

        self::assertSame([0], $hits);
    }

    public function testReturnsEmptyWhenNoChangedFiles(): void
    {
        $reflector = $this->makeLoader();
        $eps       = [$this->ep('App\\Jobs\\MyJob', $this->project . '/app/Jobs/MyJob.php')];
        self::assertSame([], TransitiveReach::affectedIndices($eps, [], $reflector, $this->project));
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
