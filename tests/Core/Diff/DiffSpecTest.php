<?php

declare(strict_types=1);

namespace Watson\Tests\Core\Diff;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Watson\Core\Diff\DiffSpec;
use Watson\Core\Diff\HeadKind;

/**
 * DiffSpec coverage uses a tempdir-scoped real git repo so the tests
 * exercise the actual git plumbing (`rev-parse`, `merge-base`) rather
 * than mocked plumbing. Each test writes one file, commits, and
 * exercises one input shape.
 */
final class DiffSpecTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir() . '/watson_diffspec_' . uniqid();
        mkdir($this->repo);
        $this->git('init', '-q', '-b', 'main');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'test');
        $this->git('config', 'commit.gpgsign', 'false');
        file_put_contents($this->repo . '/seed.txt', 'a');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'seed');
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '' && is_dir($this->repo)) {
            (new Process(['rm', '-rf', $this->repo]))->run();
        }
    }

    public function testNoArgsResolvesToWorkingTreeVsHead(): void
    {
        $spec = DiffSpec::resolve($this->repo, [], false);
        $this->assertSame(HeadKind::WorkingTree, $spec->head->kind);
        $this->assertSame('HEAD', $spec->baseDisplay);
        $this->assertSame('<working tree>', $spec->headDisplay);
        $this->assertSame(40, strlen($spec->baseSha));
    }

    public function testCachedResolvesToIndexVsHead(): void
    {
        $spec = DiffSpec::resolve($this->repo, [], true);
        $this->assertSame(HeadKind::Index, $spec->head->kind);
        $this->assertSame('<index>', $spec->headDisplay);
    }

    public function testCachedRejectsExplicitRevisions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DiffSpec::resolve($this->repo, ['HEAD'], true);
    }

    public function testRangeFormResolvesBothSidesToCommits(): void
    {
        file_put_contents($this->repo . '/b.txt', 'b');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'second');

        $spec = DiffSpec::resolve($this->repo, ['HEAD~1..HEAD'], false);
        $this->assertSame(HeadKind::Commit, $spec->head->kind);
        $this->assertSame('HEAD~1', $spec->baseDisplay);
        $this->assertSame('HEAD', $spec->headDisplay);
        $this->assertNotNull($spec->head->sha);
    }

    public function testTripleDotResolvesMergeBase(): void
    {
        file_put_contents($this->repo . '/b.txt', 'b');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'second');

        $spec = DiffSpec::resolve($this->repo, ['HEAD~1...HEAD'], false);
        $this->assertSame(HeadKind::Commit, $spec->head->kind);
        $this->assertStringContainsString('merge-base(', $spec->baseDisplay);
    }

    public function testTooManyArgsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DiffSpec::resolve($this->repo, ['a', 'b', 'c'], false);
    }

    public function testTwoArgsContainingRangeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DiffSpec::resolve($this->repo, ['a..b', 'c'], false);
    }

    private function git(string ...$args): void
    {
        $proc = new Process(['git', '-C', $this->repo, ...$args]);
        $proc->mustRun();
    }
}
