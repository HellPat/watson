<?php

declare(strict_types=1);

namespace Watson\Core\Reach\CallGraph;

use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Thin wrapper over `phpstan/phpdoc-parser` that extracts the single
 * `Class` FQN named by a `@var` / `@param` / `@return` tag.
 *
 * Scope is deliberately narrow:
 *   - one type per tag (the first one);
 *   - nullable types unwrap to their inner identifier;
 *   - union / intersection types bail (return null) — watson can't pick
 *     a single receiver for `Foo|Bar`;
 *   - generic types resolve to their base (`Collection<Foo>` → `Collection`);
 *   - scalar / pseudo-types (`int`, `string`, `mixed`, `void`, …) → null.
 *
 * Relative names are resolved against the file's `use` map + the
 * enclosing namespace — same precedence as PHP's own name resolution.
 */
final class DocblockTypeReader
{
    private PhpDocParser $parser;
    private Lexer $lexer;

    public function __construct()
    {
        $config = new ParserConfig(usedAttributes: []);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->parser = new PhpDocParser($config, $typeParser, $constExprParser);
    }

    /**
     * Resolve a `@var` tag to a class FQN.
     *
     * @param array<string, string> $useMap alias → FQN, as returned by
     *                                     {@see UseMapExtractor::extract()}
     */
    public function forProperty(?string $docComment, string $namespace, array $useMap): ?string
    {
        $doc = $this->parse($docComment);
        if ($doc === null) {
            return null;
        }
        foreach ($doc->getVarTagValues() as $tag) {
            $resolved = $this->resolveType($tag->type, $namespace, $useMap);
            if ($resolved !== null) {
                return $resolved;
            }
        }
        return null;
    }

    /**
     * Resolve a specific `@param` tag to a class FQN.
     *
     * @param array<string, string> $useMap alias → FQN
     */
    public function forParam(?string $docComment, string $paramName, string $namespace, array $useMap): ?string
    {
        $doc = $this->parse($docComment);
        if ($doc === null) {
            return null;
        }
        $target = '$' . ltrim($paramName, '$');
        foreach ($doc->getParamTagValues() as $tag) {
            if ($tag->parameterName !== $target) {
                continue;
            }
            return $this->resolveType($tag->type, $namespace, $useMap);
        }
        return null;
    }

    /**
     * Resolve the `@return` tag to a class FQN.
     *
     * @param array<string, string> $useMap alias → FQN
     */
    public function forReturn(?string $docComment, string $namespace, array $useMap): ?string
    {
        $doc = $this->parse($docComment);
        if ($doc === null) {
            return null;
        }
        foreach ($doc->getReturnTagValues() as $tag) {
            $resolved = $this->resolveType($tag->type, $namespace, $useMap);
            if ($resolved !== null) {
                return $resolved;
            }
        }
        return null;
    }

    private function parse(?string $docComment): ?PhpDocNode
    {
        if ($docComment === null || $docComment === '') {
            return null;
        }
        try {
            $tokens = new TokenIterator($this->lexer->tokenize($docComment));
            return $this->parser->parse($tokens);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, string> $useMap
     */
    private function resolveType(TypeNode $type, string $namespace, array $useMap): ?string
    {
        if ($type instanceof NullableTypeNode) {
            return $this->resolveType($type->type, $namespace, $useMap);
        }
        if ($type instanceof GenericTypeNode) {
            return $this->resolveType($type->type, $namespace, $useMap);
        }
        if (!$type instanceof IdentifierTypeNode) {
            return null; // union / intersection / array shape / etc.
        }

        return $this->resolveIdentifier($type->name, $namespace, $useMap);
    }

    /**
     * Resolve an identifier as written in source to its FQN:
     *
     *   - absolute (`\Foo\Bar`) → strip leading `\`.
     *   - first segment matches a `use` alias → splice + remainder.
     *   - else prepend the enclosing namespace.
     *
     * Scalar / pseudo-types are filtered out — `int`, `string`, `mixed`,
     * etc. don't denote a receiver class so they're not useful for
     * receiver-resolution in the call graph.
     *
     * @param array<string, string> $useMap
     */
    private function resolveIdentifier(string $name, string $namespace, array $useMap): ?string
    {
        if ($name === '' || self::isReservedName($name)) {
            return null;
        }
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        $segments = explode('\\', $name);
        $first = $segments[0];
        if (isset($useMap[$first])) {
            $segments[0] = $useMap[$first];
            return implode('\\', $segments);
        }
        if ($namespace === '') {
            return $name;
        }
        return $namespace . '\\' . $name;
    }

    private static function isReservedName(string $name): bool
    {
        return in_array(
            strtolower($name),
            [
                'self', 'static', 'parent', 'this',
                'true', 'false', 'null',
                'mixed', 'void', 'never', 'iterable', 'callable',
                'object', 'array', 'int', 'string', 'float', 'bool',
                'numeric', 'scalar', 'resource', 'positive-int', 'negative-int',
                'class-string', 'array-key', 'list', 'non-empty-string',
                'non-empty-array', 'non-empty-list',
            ],
            true,
        );
    }
}
