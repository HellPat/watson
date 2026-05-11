<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Reach\CallGraph;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Watson\Core\Reach\CallGraph\ClassIndexer;
use Watson\Core\Reach\CallGraph\DocblockTypeReader;

final class ClassIndexerTest extends TestCase
{
    private string $tmpDir;
    private ClassIndexer $indexer;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/watson_indexer_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->indexer = new ClassIndexer(
            (new ParserFactory())->createForHostVersion(),
            new DocblockTypeReader(),
        );
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $node) {
            $node->isDir() ? rmdir($node->getPathname()) : unlink($node->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    public function testNativeTypedPropertyAndMethodSignaturesAreIndexed(): void
    {
        $file = $this->write('Foo.php', '<?php
            namespace App\Service;
            class Foo {
                private Bar $bar;
                public function run(Baz $baz): Quux { return new Quux(); }
            }');
        $records = $this->indexer->indexFile($file);
        self::assertCount(1, $records);
        $meta = $records[0]['meta'];
        self::assertSame('App\\Service\\Bar', $meta['props']['bar']);
        self::assertSame('App\\Service\\Baz', $meta['methods']['run']['paramTypes']['baz']);
        self::assertSame('App\\Service\\Quux', $meta['methods']['run']['returnType']);
    }

    public function testDocblockFallbackForUntypedProperty(): void
    {
        $file = $this->write('Foo.php', '<?php
            namespace App\Service;
            use App\Service\Bar;
            class Foo {
                /** @var Bar */
                private $bar;
            }');
        $records = $this->indexer->indexFile($file);
        $meta = $records[0]['meta'];
        self::assertSame('App\\Service\\Bar', $meta['props']['bar']);
    }

    public function testDocblockFallbackForReturnType(): void
    {
        $file = $this->write('Foo.php', '<?php
            namespace App\Service;
            class Foo {
                /** @return \App\Service\Bar */
                public function make() { return null; }
            }');
        $meta = $this->indexer->indexFile($file)[0]['meta'];
        self::assertSame('App\\Service\\Bar', $meta['methods']['make']['returnType']);
    }

    public function testDocblockFallbackForParam(): void
    {
        $file = $this->write('Foo.php', '<?php
            namespace App\Service;
            use App\Service\Bar;
            class Foo {
                /** @param Bar $svc */
                public function take($svc): void {}
            }');
        $meta = $this->indexer->indexFile($file)[0]['meta'];
        self::assertSame('App\\Service\\Bar', $meta['methods']['take']['paramTypes']['svc']);
    }

    public function testUseMapResolvesAliasedDocblockType(): void
    {
        $file = $this->write('Foo.php', '<?php
            namespace App\Web;
            use App\Service\Bar as BarSvc;
            class Foo {
                /** @return BarSvc */
                public function make() {}
            }');
        $meta = $this->indexer->indexFile($file)[0]['meta'];
        self::assertSame('App\\Service\\Bar', $meta['methods']['make']['returnType']);
    }

    public function testConstructorPromotionRegistersProperty(): void
    {
        $file = $this->write('Foo.php', '<?php
            namespace App\Service;
            class Foo {
                public function __construct(private Bar $bar) {}
            }');
        $meta = $this->indexer->indexFile($file)[0]['meta'];
        self::assertSame('App\\Service\\Bar', $meta['props']['bar']);
    }

    public function testInterfaceAndTraitsCaptured(): void
    {
        $file = $this->write('Foo.php', '<?php
            namespace App\Service;
            class Foo extends BaseClass implements ContractA, ContractB {
                use TraitX, TraitY;
            }');
        $meta = $this->indexer->indexFile($file)[0]['meta'];
        self::assertSame('App\\Service\\BaseClass', $meta['parent']);
        self::assertContains('App\\Service\\ContractA', $meta['interfaces']);
        self::assertContains('App\\Service\\ContractB', $meta['interfaces']);
        self::assertContains('App\\Service\\TraitX', $meta['traits']);
        self::assertContains('App\\Service\\TraitY', $meta['traits']);
    }

    private function write(string $name, string $code): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $code);
        return $path;
    }
}
