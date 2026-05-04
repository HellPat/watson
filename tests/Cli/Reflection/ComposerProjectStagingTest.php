<?php

declare(strict_types=1);

namespace Watson\Tests\Cli\Reflection;

use PHPUnit\Framework\TestCase;
use Watson\Cli\Reflection\ComposerProjectStaging;

final class ComposerProjectStagingTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/watson_staging_test_' . uniqid();
        mkdir($this->project . '/vendor/composer', 0700, true);
        mkdir($this->project . '/vendor/acme/lib/src', 0700, true);
        file_put_contents($this->project . '/vendor/acme/lib/src/Foo.php', '<?php class Foo {}');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->project);
    }

    public function testReturnsOriginalRootWhenNoEmptyPrefix(): void
    {
        $this->writeJson('composer.json', [
            'name' => 'acme/app',
            'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
        ]);
        $this->writeJson('vendor/composer/installed.json', [
            'packages' => [
                ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            ],
        ]);

        self::assertSame($this->project, ComposerProjectStaging::prepare($this->project));
    }

    public function testStagesSanitizedRootWhenInstalledHasEmptyPrefix(): void
    {
        $this->writeJson('composer.json', [
            'name' => 'acme/app',
            'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
        ]);
        $this->writeJson('vendor/composer/installed.json', [
            'packages' => [
                [
                    'name'     => 'kylekatarnls/carbonite',
                    'autoload' => ['psr-4' => ['' => 'src/', 'Carbonite\\' => 'src/']],
                ],
                ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            ],
        ]);

        $stage = ComposerProjectStaging::prepare($this->project);
        self::assertNotSame($this->project, $stage);
        self::assertDirectoryExists($stage);
        self::assertFileExists($stage . '/composer.json');
        self::assertFileExists($stage . '/vendor/composer/installed.json');

        $sanitized = json_decode((string) file_get_contents($stage . '/vendor/composer/installed.json'), true);
        self::assertIsArray($sanitized);
        $carbonite = $sanitized['packages'][0]['autoload']['psr-4'] ?? [];
        self::assertArrayNotHasKey('', $carbonite);
        self::assertArrayHasKey('Carbonite\\', $carbonite);

        // Other vendor packages must still be reachable through the staging dir.
        self::assertTrue(is_link($stage . '/vendor/acme') || is_dir($stage . '/vendor/acme'));
        self::assertFileExists($stage . '/vendor/acme/lib/src/Foo.php');
    }

    public function testStripsEmptyPrefixFromConsumerComposer(): void
    {
        $this->writeJson('composer.json', [
            'name'     => 'acme/app',
            'autoload' => ['psr-4' => ['' => 'src/', 'Acme\\' => 'src/']],
        ]);
        $this->writeJson('vendor/composer/installed.json', ['packages' => []]);

        $stage = ComposerProjectStaging::prepare($this->project);
        self::assertNotSame($this->project, $stage);

        $composer = json_decode((string) file_get_contents($stage . '/composer.json'), true);
        self::assertIsArray($composer);
        $psr4 = $composer['autoload']['psr-4'] ?? [];
        self::assertArrayNotHasKey('', $psr4);
        self::assertArrayHasKey('Acme\\', $psr4);
    }

    public function testHandlesListShapedInstalledJson(): void
    {
        $this->writeJson('composer.json', ['name' => 'acme/app']);
        $this->writeJson('vendor/composer/installed.json', [
            ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['' => 'src/']]],
        ]);

        $stage = ComposerProjectStaging::prepare($this->project);
        self::assertNotSame($this->project, $stage);

        $sanitized = json_decode((string) file_get_contents($stage . '/vendor/composer/installed.json'), true);
        self::assertIsArray($sanitized);
        self::assertArrayNotHasKey('', $sanitized[0]['autoload']['psr-4'] ?? []);
    }

    public function testReturnsOriginalRootWhenJsonMissing(): void
    {
        // No composer.json on disk.
        $this->rmrf($this->project . '/vendor');
        self::assertSame($this->project, ComposerProjectStaging::prepare($this->project));
    }

    private function writeJson(string $relPath, array $data): void
    {
        $full = $this->project . '/' . $relPath;
        @mkdir(dirname($full), 0700, true);
        file_put_contents($full, (string) json_encode($data, JSON_UNESCAPED_SLASHES));
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
