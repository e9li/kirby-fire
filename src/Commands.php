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
}
