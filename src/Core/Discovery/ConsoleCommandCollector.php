<?php

declare(strict_types=1);

namespace Watson\Core\Discovery;

use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Watson\Core\Entrypoint\EntryPoint;
use Watson\Core\Entrypoint\Source;

/**
 * AST-based Symfony Console command collector for standalone CLI
 * tools (no `bin/console debug:container` to ask). Yields any
 * concrete class that either
 *
 *   - extends `Symfony\Component\Console\Command\Command`, OR
 *   - carries the `#[AsCommand]` attribute.
 *
 * Pure nikic/php-parser via {@see ClassIndex} for the discovery walk;
 * a second AST pass per matched file pulls the `#[AsCommand]` argument
 * (so we keep the public command name, not just the FQN).
 *
 * Directories to scan are *not* hard-coded — callers derive them from
 * the project's own `composer.json` PSR-4 autoload roots so we follow
 * the project's declared layout rather than guess at conventions.
 */
final class ConsoleCommandCollector
{
    private const COMMAND_BASE    = 'Symfony\\Component\\Console\\Command\\Command';
    private const AS_COMMAND_ATTR = 'Symfony\\Component\\Console\\Attribute\\AsCommand';

    /**
     * @param list<string> $dirs absolute paths
     * @return list<EntryPoint>
     */
    public static function collect(array $dirs): array
    {
        if ($dirs === []) {
            return [];
        }
        $index = ClassIndex::buildFromDirs($dirs);
        $out = [];
        foreach ($index->all() as $entry) {
            if ($entry->isAbstract || $entry->isInterface || $entry->isTrait) {
                continue;
            }
            $hasAttr = in_array(self::AS_COMMAND_ATTR, $entry->attributeNames, true);
            $isCommand = $hasAttr || $index->isSubclassOf($entry->fqn, self::COMMAND_BASE);
            if (!$isCommand) {
                continue;
            }
            $commandName = $hasAttr ? self::readCommandName($entry->file, $entry->fqn) : null;
            $execute = $entry->methods['execute'] ?? null;
            $handlerFqn = $execute !== null ? $entry->fqn . '::execute' : $entry->fqn;
            $line       = $execute !== null ? ($execute->startLine ?: $entry->startLine) : $entry->startLine;

            $out[] = new EntryPoint(
                kind: 'symfony.command',
                name: $commandName ?? $entry->fqn,
                handlerFqn: $handlerFqn,
                handlerPath: $entry->file,
                handlerLine: $line,
                source: Source::Interface_,
            );
        }
        return $out;
    }

    /**
     * Pull the `name` argument off a `#[AsCommand]` attribute on a
     * specific class within `$file`. Symfony accepts both
     * `#[AsCommand('foo')]` and `#[AsCommand(name: 'foo')]` — we
     * check the named form first, then the first positional argument.
     */
    private static function readCommandName(string $file, string $classFqn): ?string
    {
        $code = @file_get_contents($file);
        if (!is_string($code)) {
            return null;
        }
        try {
            $ast = (new ParserFactory())->createForHostVersion()->parse($code);
        } catch (\Throwable) {
            return null;
        }
        if ($ast === null) {
            return null;
        }
        $visitor = new AsCommandArgVisitor($classFqn);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        return $visitor->name;
    }
}

/**
 * @internal
 */
final class AsCommandArgVisitor extends NodeVisitorAbstract
{
    public ?string $name = null;

    public function __construct(private readonly string $classFqn) {}

    public function enterNode(Node $node): null
    {
        if (!($node instanceof Class_)) {
            return null;
        }
        if ($node->namespacedName?->toString() !== $this->classFqn) {
            return null;
        }
        foreach ($node->attrGroups as $group) {
            $extracted = self::extractFromGroup($group);
            if ($extracted !== null) {
                $this->name = $extracted;
                return null;
            }
        }
        return null;
    }

    private static function extractFromGroup(AttributeGroup $group): ?string
    {
        foreach ($group->attrs as $attr) {
            if ($attr->name->toString() !== 'Symfony\\Component\\Console\\Attribute\\AsCommand') {
                continue;
            }
            foreach ($attr->args as $arg) {
                if ($arg->name !== null && $arg->name->toString() !== 'name') {
                    continue;
                }
                $value = $arg->value;
                if ($value instanceof Node\Scalar\String_) {
                    return $value->value !== '' ? $value->value : null;
                }
            }
        }
        return null;
    }
}
