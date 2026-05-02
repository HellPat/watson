<?php

declare(strict_types=1);

namespace Watson\Core\Reach;

use Watson\Core\Entrypoint\EntryPoint;

/**
 * The file-level reach pass that turned out to be watson's most useful
 * signal on real Laravel apps with heavy interface-DI: forget the static
 * call graph, just check whether each entry point's handler file is in
 * the diff. If yes, the entry point is potentially affected.
 *
 * High recall, modest precision (a docblock-only edit shows up). Consumers
 * filter by confidence.
 */
final class FileLevelReach
{
    /**
     * @param list<EntryPoint> $entryPoints
     * @param list<string> $changedFiles absolute paths
     * @return list<int> indices into `$entryPoints` for affected entries
     */
    public static function affectedIndices(array $entryPoints, array $changedFiles): array
    {
        $changedSet = [];
        foreach ($changedFiles as $f) {
            $real = realpath($f);
            if ($real !== false) {
                $changedSet[$real] = true;
            }
            // Also track the raw path so non-existent (deleted) files match.
            $changedSet[$f] = true;
        }

        $hits = [];
        foreach ($entryPoints as $idx => $ep) {
            $real = realpath($ep->handlerPath);
            $key = $real !== false ? $real : $ep->handlerPath;
            if (isset($changedSet[$key])) {
                $hits[] = $idx;
            }
        }

        return $hits;
    }
}
