<?php

namespace E9li\Fire;

/**
 * Shared pieces of the CLI commands.
 */
class Commands
{
    /**
     * Preflight warning: without an active pages cache every "warmed" page
     * is rendered and thrown away — Kirby silently skips caching.
     */
    public static function cacheWarning($cli): void
    {
        if ((kirby()->cache('pages')->options()['active'] ?? false) === false) {
            $cli->error(' The pages cache is not active — nothing will be cached! ');
            $cli->out('Enable it in site/config/config.php: \'cache\' => [\'pages\' => [\'active\' => true]]');
        }
    }

    /**
     * --fresh handling: flushes the pages cache so the run rebuilds
     * everything (and page renders re-queue their thumb jobs).
     */
    public static function freshFlush($cli): void
    {
        try {
            kirby()->cache('pages')->flush();
            $cli->out('Pages cache flushed.');
        } catch (\Throwable) {
            // pages cache not available — nothing to flush
        }
    }

    /**
     * Raises the CLI memory limit, like Composer does for itself: thumb
     * generation decodes whole images into memory (a 24 MP photo needs
     * ~100 MB in GD alone), and shared-hosting limits of 128 MB die
     * mid-run — uncatchably. Never lowers an existing limit; set the
     * memory option to false to keep the environment's limit.
     */
    public static function raiseMemory(): void
    {
        $target = kirby()->option('e9li.kirby-fire.memory', '512M');

        if ($target === false) {
            return;
        }

        $current = (string)ini_get('memory_limit');

        if ($current === '-1') {
            return;
        }

        if ((string)$target === '-1') {
            @ini_set('memory_limit', '-1');

            return;
        }

        if (static::toBytes((string)$target) > static::toBytes($current)) {
            @ini_set('memory_limit', (string)$target);
        }
    }

    /**
     * php.ini shorthand (128M, 1G, 512K) to bytes.
     */
    public static function toBytes(string $value): float
    {
        $number = (float)$value;

        return match (strtoupper(substr(trim($value), -1))) {
            'K' => $number * 1024,
            'M' => $number * 1024 ** 2,
            'G' => $number * 1024 ** 3,
            default => $number,
        };
    }
}
