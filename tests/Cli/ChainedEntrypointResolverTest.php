<?php

declare(strict_types=1);

namespace Watson\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Watson\Cli\ChainedEntrypointResolver;
use Watson\Cli\Project;
use Watson\Cli\Reflection\StaticReflector;
use Watson\Cli\ResolverOptions;
use Watson\Cli\Source\EntrypointSource;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;
use Watson\Core\Output\SourceRunStatus;

final class ChainedEntrypointResolverTest extends TestCase
{
    public function testReportsSkippedSourcesWithoutRunningThem(): void
    {
        $source = new class implements EntrypointSource {
            public bool $collectCalled = false;
            public function name(): string { return 'fake.skipped'; }
            public function canHandle(Project $project): bool { return false; }
            public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
            {
                $this->collectCalled = true;
                return [];
            }
        };
        $resolver = new ChainedEntrypointResolver([$source]);

        $out = $resolver->collect(new Project('/tmp/proj'), new ResolverOptions());

        self::assertFalse($source->collectCalled);
        self::assertSame([], $out['entryPoints']);
        self::assertCount(1, $out['sourceReport']);
        self::assertSame(SourceRunStatus::Skipped, $out['sourceReport'][0]->status);
        self::assertSame('fake.skipped', $out['sourceReport'][0]->name);
    }

    public function testTagsContributionsWithSourceName(): void
    {
        $entry = new EntryPoint(
            kind: 'symfony.command',
            name: 'app:ping',
            handlerFqn: 'App\\PingCommand::execute',
            handlerPath: '/abs/PingCommand.php',
            handlerLine: 10,
            source: Source::Interface_,
        );
        $source = new class($entry) implements EntrypointSource {
            public function __construct(private readonly EntryPoint $entry) {}
            public function name(): string { return 'fake.runs'; }
            public function canHandle(Project $project): bool { return true; }
            public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
            {
                return [$this->entry];
            }
        };
        $resolver = new ChainedEntrypointResolver([$source]);

        $out = $resolver->collect(new Project('/tmp/proj'), new ResolverOptions());

        self::assertCount(1, $out['entryPoints']);
        self::assertSame('fake.runs', $out['entryPoints'][0]->discoveredBy);
        self::assertSame(SourceRunStatus::Ran, $out['sourceReport'][0]->status);
        self::assertSame(1, $out['sourceReport'][0]->count);
    }

    public function testFailedSourceDoesNotKillOthers(): void
    {
        $okEntry = new EntryPoint(
            kind: 'phpunit.test',
            name: 'Foo::test',
            handlerFqn: 'App\\FooTest::test',
            handlerPath: '/abs/FooTest.php',
            handlerLine: 5,
            source: Source::Interface_,
        );
        $failing = new class implements EntrypointSource {
            public function name(): string { return 'fake.fails'; }
            public function canHandle(Project $project): bool { return true; }
            public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
            {
                throw new \RuntimeException('boom');
            }
        };
        $ok = new class($okEntry) implements EntrypointSource {
            public function __construct(private readonly EntryPoint $entry) {}
            public function name(): string { return 'fake.ok'; }
            public function canHandle(Project $project): bool { return true; }
            public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
            {
                return [$this->entry];
            }
        };
        $resolver = new ChainedEntrypointResolver([$failing, $ok]);

        $out = $resolver->collect(new Project('/tmp/proj'), new ResolverOptions());

        self::assertCount(1, $out['entryPoints']);
        self::assertSame('fake.ok', $out['entryPoints'][0]->discoveredBy);
        self::assertSame(SourceRunStatus::Failed, $out['sourceReport'][0]->status);
        self::assertSame('boom', $out['sourceReport'][0]->error);
        self::assertSame(SourceRunStatus::Ran, $out['sourceReport'][1]->status);
    }

    public function testDedupsByKindPlusHandlerFqnPreservingEarlierSource(): void
    {
        $shared = new EntryPoint(
            kind: 'symfony.command',
            name: 'app:ping',
            handlerFqn: 'App\\PingCommand::execute',
            handlerPath: '/abs/PingCommand.php',
            handlerLine: 10,
            source: Source::Runtime,
        );
        $runtime = new class($shared) implements EntrypointSource {
            public function __construct(private readonly EntryPoint $entry) {}
            public function name(): string { return 'runtime'; }
            public function canHandle(Project $project): bool { return true; }
            public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
            {
                return [$this->entry];
            }
        };
        $ast = new class($shared) implements EntrypointSource {
            public function __construct(private readonly EntryPoint $entry) {}
            public function name(): string { return 'ast'; }
            public function canHandle(Project $project): bool { return true; }
            public function collect(Project $project, StaticReflector $reflector, ResolverOptions $opts): iterable
            {
                return [$this->entry];
            }
        };
        $resolver = new ChainedEntrypointResolver([$runtime, $ast]);

        $out = $resolver->collect(new Project('/tmp/proj'), new ResolverOptions());

        self::assertCount(1, $out['entryPoints']);
        self::assertSame('runtime', $out['entryPoints'][0]->discoveredBy);
    }
}
