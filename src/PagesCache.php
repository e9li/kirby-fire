<?php

namespace E9li\Fire;

use FilesystemIterator;
use Kirby\Cache\FileCache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * State of Kirby's pages cache, shown in the Panel so "no fire" rows are
 * never mistaken for an empty cache.
 */
class PagesCache
{
    /**
     * Whether the pages cache is active, and — for the file driver, the
     * only one whose entries can be listed — how many entries it holds.
     */
    public static function status(): array
    {
        $cache = kirby()->cache('pages');
        $active = ($cache->options()['active'] ?? false) === true;
        $count = null;

        if ($active === true && $cache instanceof FileCache) {
            $count = 0;
            $root = $cache->root();

            if (is_dir($root) === true) {
                $tree = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($tree as $file) {
                    if ($file->isFile() === true && $file->getExtension() === 'cache') {
                        $count++;
                    }
                }
            }
        }

        return [
            'active' => $active,
            'count' => $count,
        ];
    }
}
