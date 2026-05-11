<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Reach\CallGraph;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Watson\Core\Reach\CallGraph\MethodResolver;
use Watson\Core\Reach\CallGraph\SymbolGraph;

final class MethodResolverTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/watson_mr_' . uniqid();
        mkdir($this->project . '/app/Service', 0700, true);
        mkdir($this->project . '/vendor/composer', 0700, true);
        file_put_contents($this->project . '/vendor/composer/installed.json', '{"packages":[]}');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->project);
    }

    public function testStaticCallEdgeResolved(): void
    {
        $a = $this->write('app/Service/A.php', '<?php
            namespace App\Service;
            class A {
                public function run(): void {
                    B::ping();
                }
            }');
        $this->write('app/Service/B.php', '<?php
            namespace App\Service;
            class B {
                public static function ping(): void {}
            }');

        $g = $this->build([$a]);
        $edges = $this->targets($g, 'App\\Service\\A::run');
        self::assertContains('App\\Service\\B::ping', $edges);
    }

    public function testNewExpressionEmitsConstructorEdge(): void
    {
        $a = $this->write('app/Service/A.php', '<?php
            namespace App\Service;
            class A {
                public function run(): void {
                    new B();
                }
            }');
        $this->write('app/Service/B.php', '<?php
            namespace App\Service;
            class B {
                public function __construct() {}
            }');

        $g = $this->build([$a]);
        self::assertContains('App\\Service\\B::__construct', $this->targets($g, 'App\\Service\\A::run'));
    }

    public function testTypedParameterResolvesInstanceCall(): void
    {
        $a = $this->write('app/Service/A.php', '<?php
            namespace App\Service;
            class A {
                public function run(B $b): void {
                    $b->ping();
                }
            }');
        $this->write('app/Service/B.php', '<?php
            namespace App\Service;
            class B {
                public function ping(): void {}
            }');

        $g = $this->build([$a]);
        self::assertContains('App\\Service\\B::ping', $this->targets($g, 'App\\Service\\A::run'));
    }

    public function testTypedPropertyResolvesInstanceCall(): void
    {
        $a = $this->write('app/Service/A.php', '<?php
            namespace App\Service;
            class A {
                private B $b;
                public function run(): void {
                    $this->b->ping();
                }
            }');
        $this->write('app/Service/B.php', '<?php
            namespace App\Service;
            class B {
                public function ping(): void {}
            }');

        $g = $this->build([$a]);
        self::assertContains('App\\Service\\B::ping', $this->targets($g, 'App\\Service\\A::run'));
    }

    public function testConstructorPromotionResolvesPropertyType(): void
    {
        $a = $this->write('app/Service/A.php', '<?php
            namespace App\Service;
            class A {
                public function __construct(private B $b) {}
                public function run(): void {
                    $this->b->ping();
                }
            }');
        $this->write('app/Service/B.php', '<?php
            namespace App\Service;
            class B {
                public function ping(): void {}
            }');

        $g = $this->build([$a]);
        self::assertContains('App\\Service\\B::ping', $this->targets($g, 'App\\Service\\A::run'));
    }

    public function testLocalNewThenCallResolves(): void
    {
        $a = $this->write('app/Service/A.php', '<?php
            namespace App\Service;
            class A {
                public function run(): void {
                    $x = new B();
                    $x->ping();
                }
            }');
        $this->write('app/Service/B.php', '<?php
            namespace App\Service;
            class B {
                public function ping(): void {}
            }');

        $g = $this->build([$a]);
        self::assertContains('App\\Service\\B::ping', $this->targets($g, 'App\\Service\\A::run'));
    }

    public function testUnknownReceiverFallsBackToNameOnly(): void
    {
        $a = $this->write('app/Service/A.php', '<?php
            namespace App\Service;
            class A {
                public function run($mystery): void {
                    $mystery->ping();
                }
            }');
        $this->write('app/Service/B.php', '<?php
            namespace App\Service;
            class B {
                public function ping(): void {}
            }');

        $g = $this->build([$a]);
        $edges = $g->edges['App\\Service\\A::run'] ?? [];
        $kinds = array_column($edges, 'kind');
        self::assertContains(SymbolGraph::KIND_NAME_ONLY, $kinds);
    }

    public function testExtendsResolvesInheritedMethod(): void
    {
        $a = $this->write('app/Service/A.php', '<?php
            namespace App\Service;
            class A extends Base {
                public function run(): void {
                    Base::ping();
                }
            }');
        $this->write('app/Service/Base.php', '<?php
            namespace App\Service;
            class Base {
                public static function ping(): void {}
            }');

        $g = $this->build([$a]);
        self::assertSame('App\\Service\\Base', $g->ownerOfMethod('App\\Service\\A', 'ping'));
    }

    private function build(array $seeds): SymbolGraph
    {
        $loader = new ClassLoader();
        $loader->addPsr4('App\\', $this->project . '/app/');
        return MethodResolver::build($seeds, $loader, $this->project);
    }

    /** @return list<string> */
    private function targets(SymbolGraph $g, string $caller): array
    {
        return array_values(array_unique(array_column($g->edges[$caller] ?? [], 'target')));
    }

    private function write(string $rel, string $contents): string
    {
        $abs = $this->project . '/' . $rel;
        if (!is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0700, true);
        }
        file_put_contents($abs, $contents);
        return $abs;
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $node) {
            $node->isDir() ? rmdir($node->getPathname()) : unlink($node->getPathname());
        }
        rmdir($dir);
    }
}
