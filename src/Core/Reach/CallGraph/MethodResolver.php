<?php

declare(strict_types=1);

namespace Watson\Core\Reach\CallGraph;

use Composer\Autoload\ClassLoader;
use PhpParser\ParserFactory;

/**
 * Thin façade kept for backwards compatibility with callers that don't
 * want to assemble the {@see ClassIndexer} + {@see MethodBodyAnalyzer}
 * + {@see CallGraphBuilder} trio themselves.
 *
 * The real work is split across the three collaborators above — each
 * with a single responsibility, all small enough to unit-test in
 * isolation.
 */
final class MethodResolver
{
    /**
     * @param list<string> $seedFiles absolute project file paths
     */
    public static function build(
        array $seedFiles,
        ClassLoader $classLoader,
        string $projectRoot,
        int $maxFiles = 5000,
    ): SymbolGraph {
        $rootReal = realpath($projectRoot);
        if ($rootReal === false) {
            return new SymbolGraph([], [], [], []);
        }
        $vendorReal = realpath($projectRoot . '/vendor');

        $parser   = (new ParserFactory())->createForHostVersion();
        $docs     = new DocblockTypeReader();
        $indexer  = new ClassIndexer($parser, $docs);
        $analyzer = new MethodBodyAnalyzer();
        $builder  = new CallGraphBuilder($indexer, $analyzer, $classLoader, $rootReal, $vendorReal, $maxFiles);

        return $builder->build($seedFiles);
    }
}
