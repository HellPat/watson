<?php

declare(strict_types=1);

namespace Watson\Core\Diff;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use SebastianBergmann\Diff\Line;
use SebastianBergmann\Diff\Parser as UnifiedDiffParser;

/**
 * Map a unified diff (produced by `git diff -W -U99999`) to a list of
 * {@see ChangedSymbol}s where each symbol represents a *semantic* change —
 * comment-only and whitespace-only edits are filtered out by AST hashing.
 *
 * Input contract:
 *
 *   git diff -W -U99999 <ref> | watson blastradius --unified-diff
 *
 * `-W` ensures hunks span the whole function; `-U99999` ensures the hunk
 * carries the full file so old and new can be reconstructed in-process
 * without a baseline directory or a git shell-out.
 *
 * Algorithm per file:
 *   1. Reconstruct old text = context + `-` lines, new text = context + `+`.
 *   2. Parse both as PHP, walk Class/Interface/Trait/Enum → ClassMethod.
 *   3. Hash each method's body AST (comments + docblocks stripped, whitespace
 *      normalised by the pretty-printer).
 *   4. Compare the two `Class::method → hash` maps:
 *        - present both + hash equal → drop (no semantic change).
 *        - present both + hash differs → ChangedSymbol(Class::method).
 *        - new-only → ChangedSymbol(Class::method) [added].
 *        - old-only → ChangedSymbol(Class::method) [deleted, endLine = startLine].
 *      Plus: class-body edits outside any method → ChangedSymbol(Class::*).
 *      Plus: file-body edits outside any class → ChangedSymbol(file).
 */
final class AstDiffMapper
{
    public static function map(string $diffText, string $projectRoot): array
    {
        $files  = self::parseDiff($diffText);
        $parser = (new ParserFactory())->createForHostVersion();
        $out    = [];

        foreach ($files as $file) {
            $absPath = self::absolutise($file['path'], $projectRoot);
            $oldMap  = self::buildSymbolMap($file['oldText'], $parser);
            $newMap  = self::buildSymbolMap($file['newText'], $parser);

            foreach (self::diffMaps($oldMap, $newMap, $absPath) as $cs) {
                $out[] = $cs;
            }
        }

        return $out;
    }

    /**
     * Parse a unified diff into per-file old/new text reconstructions.
     *
     * Delegates the unified-diff lexing to {@see UnifiedDiffParser}
     * (sebastian/diff — battle-tested by PHPUnit + ParaTest). We only
     * map the resulting Diff/Chunk/Line tree into the (path, oldText,
     * newText) triple our AST diff cares about, picking the post-image
     * filename for new/modified files and the pre-image for deletions.
     *
     * @return list<array{path: string, oldText: string, newText: string}>
     */
    private static function parseDiff(string $diffText): array
    {
        $files = [];
        foreach ((new UnifiedDiffParser())->parse($diffText) as $diff) {
            $added   = self::stripPathHeader($diff->from()) === '/dev/null';
            $deleted = self::stripPathHeader($diff->to())   === '/dev/null';
            $path    = self::stripPathHeader($deleted ? $diff->from() : $diff->to());
            if ($path === '' || $path === '/dev/null') {
                continue;
            }

            $oldLines = [];
            $newLines = [];
            foreach ($diff->chunks() as $chunk) {
                foreach ($chunk->lines() as $line) {
                    if ($line->isUnchanged()) {
                        $oldLines[] = $line->content();
                        $newLines[] = $line->content();
                    } elseif ($line->isRemoved()) {
                        $oldLines[] = $line->content();
                    } elseif ($line->isAdded()) {
                        $newLines[] = $line->content();
                    }
                }
            }

            $files[] = [
                'path'    => $path,
                'oldText' => $added   ? '' : implode("\n", $oldLines),
                'newText' => $deleted ? '' : implode("\n", $newLines),
            ];
        }

        return $files;
    }

    /**
     * Drop the canonical `a/` / `b/` git-diff path prefix and any
     * trailing `\t<timestamp>` some diff producers append (`unified=NN`
     * isn't affected, but `diff -u --label` and a few SVN exporters
     * are).
     */
    private static function stripPathHeader(string $rest): string
    {
        $rest = rtrim($rest);
        $tabAt = strpos($rest, "\t");
        if ($tabAt !== false) {
            $rest = substr($rest, 0, $tabAt);
        }
        if (str_starts_with($rest, 'b/') || str_starts_with($rest, 'a/')) {
            return substr($rest, 2);
        }
        return $rest;
    }

    /**
     * Parse $code (which may be empty) and return a map of symbol identity →
     * AST-hash + line span. Symbols are:
     *
     *   "Class::method"  — a real method body
     *   "Class::*"       — class body outside methods (constants / properties)
     *   "@file"          — anything outside any class
     *
     * @return array<string, array{hash: string, startLine: int, endLine: int}>
     */
    private static function buildSymbolMap(string $code, Parser $parser): array
    {
        if ($code === '') {
            return [];
        }
        try {
            $ast = $parser->parse($code);
        } catch (\Throwable) {
            return [];
        }
        if ($ast === null) {
            return [];
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $map      = [];
        $printer  = new Standard();
        $fileBody = [];

        $walker = function (array $nodes, ?string $classFqn = null) use (&$walker, &$map, $printer, &$fileBody): void {
            foreach ($nodes as $node) {
                if ($node instanceof Class_ || $node instanceof Trait_ || $node instanceof Interface_ || $node instanceof Enum_) {
                    $name = $node->namespacedName?->toString() ?? $node->name?->toString();
                    if ($name === null) {
                        continue;
                    }
                    $methodsSeen = false;
                    $classBody   = [];
                    foreach ($node->stmts ?? [] as $stmt) {
                        if ($stmt instanceof ClassMethod) {
                            $methodsSeen = true;
                            $methodName  = $stmt->name->toString();
                            $hash        = self::hashNode($stmt, $printer);
                            $map[$name . '::' . $methodName] = [
                                'hash'      => $hash,
                                'startLine' => $stmt->getStartLine(),
                                'endLine'   => $stmt->getEndLine(),
                            ];
                        } else {
                            $classBody[] = $stmt;
                        }
                    }
                    if ($classBody !== []) {
                        $hash = self::hashNodes($classBody, $printer);
                        $map[$name . '::*'] = [
                            'hash'      => $hash,
                            'startLine' => $node->getStartLine(),
                            'endLine'   => $node->getEndLine(),
                        ];
                    }
                    if (!$methodsSeen && $classBody === []) {
                        $map[$name . '::*'] = [
                            'hash'      => 'empty',
                            'startLine' => $node->getStartLine(),
                            'endLine'   => $node->getEndLine(),
                        ];
                    }
                    continue;
                }
                if ($node instanceof Node\Stmt\Namespace_) {
                    $walker($node->stmts ?? [], $classFqn);
                    continue;
                }
                // Anything else at the top level — collect for the `@file` bucket.
                $fileBody[] = $node;
            }
        };
        $walker($ast);

        if ($fileBody !== []) {
            $map['@file'] = [
                'hash'      => self::hashNodes($fileBody, new Standard()),
                'startLine' => 1,
                'endLine'   => 1,
            ];
        }

        return $map;
    }

    private static function hashNode(Node $node, Standard $printer): string
    {
        $clone = self::stripComments($node);
        return md5($printer->prettyPrint([$clone]));
    }

    /** @param list<Node> $nodes */
    private static function hashNodes(array $nodes, Standard $printer): string
    {
        $cloned = [];
        foreach ($nodes as $n) {
            $cloned[] = self::stripComments($n);
        }
        return md5($printer->prettyPrint($cloned));
    }

    /**
     * Deep-clone $node and clear `comments` attributes so docblocks +
     * trailing comments don't affect the hash.
     */
    private static function stripComments(Node $node): Node
    {
        $clone = clone $node;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class extends \PhpParser\NodeVisitorAbstract {
            public function enterNode(Node $node): null|Node
            {
                $node->setAttribute('comments', []);
                return null;
            }
        });
        $stmts = $traverser->traverse([$clone]);
        return $stmts[0];
    }

    /**
     * @param array<string, array{hash:string,startLine:int,endLine:int}> $old
     * @param array<string, array{hash:string,startLine:int,endLine:int}> $new
     * @return list<ChangedSymbol>
     */
    private static function diffMaps(array $old, array $new, string $absPath): array
    {
        $out  = [];
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);

        foreach ($keys as $key) {
            $o = $old[$key] ?? null;
            $n = $new[$key] ?? null;

            if ($o !== null && $n !== null && $o['hash'] === $n['hash']) {
                continue; // semantic no-op
            }

            $cs = self::toChangedSymbol($key, $absPath, $n ?? $o);
            $out[] = $cs;
        }

        return $out;
    }

    /**
     * @param array{hash:string,startLine:int,endLine:int} $span
     */
    private static function toChangedSymbol(string $key, string $absPath, array $span): ChangedSymbol
    {
        if ($key === '@file') {
            return new ChangedSymbol($absPath, null, null, $span['startLine'], $span['endLine']);
        }

        $sep = strrpos($key, '::');
        if ($sep === false) {
            return new ChangedSymbol($absPath, null, null, $span['startLine'], $span['endLine']);
        }
        $class = substr($key, 0, $sep);
        $tail  = substr($key, $sep + 2);
        $method = $tail === '*' ? null : $tail;

        return new ChangedSymbol($absPath, $class, $method, $span['startLine'], $span['endLine']);
    }

    private static function absolutise(string $path, string $projectRoot): string
    {
        if ($path === '') {
            return '';
        }
        if ($path[0] === DIRECTORY_SEPARATOR) {
            return $path;
        }
        return rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }
}
