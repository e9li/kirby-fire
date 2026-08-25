<?php

namespace E9li\Fire;

/**
 * Warms the pages cache in-process: renders every page per language without
 * any HTTP. Page::render() checks and fills the pages cache itself, and the
 * cache id (page id + language + version + content type) is request
 * independent — entries written here are exactly what HTTP requests read.
 * Rendering also queues the thumb job files that fire:thumbs works off, so
 * "fire:render && fire:thumbs" is a complete warm-up without a webserver:
 * immune to loopback restrictions, single-worker dev servers and TLS, and
 * every generated file belongs to one user instead of being split between
 * the webserver and the CLI.
 */
class Renderer
{
    /**
     * Renders every target page. Pages that are already cached come back
     * from the cache, so re-running is incremental. $onResult runs per page.
     */
    public static function renderAll(?callable $onResult = null, ?array $targets = null): array
    {
        $kirby = kirby();
        $targets ??= Pages::targets();
        $results = [];
        $inFlight = null;

        // Kirby's go() helper ends the process with die() — uncatchable.
        // Without this guard a single redirecting template kills the whole
        // run silently, with no way to tell which page was responsible.
        register_shutdown_function(function () use (&$inFlight): void {
            if ($inFlight === null) {
                return;
            }

            echo PHP_EOL . 'Aborted while rendering ' . $inFlight['url'] . PHP_EOL;
            echo 'The page ended the process — typically a template that redirects via go().' . PHP_EOL;
            echo 'Skip its template and run again:' . PHP_EOL;
            echo "'e9li.kirby-fire' => ['ignore' => ['template' => ['" . $inFlight['template'] . "']]]" . PHP_EOL;
        });

        foreach ($targets as $target) {
            if ($kirby->multilang() === true && $target['language'] !== null) {
                $kirby->setCurrentLanguage($target['language']);
            }

            $inFlight = [
                'url' => $target['url'],
                'template' => $target['template'],
            ];

            try {
                $target['page']->render();
                $result = ['url' => $target['url'], 'ok' => true, 'error' => null];
            } catch (\Throwable $e) {
                $result = ['url' => $target['url'], 'ok' => false, 'error' => $e->getMessage()];
            }

            $inFlight = null;
            $results[] = $result;

            if ($onResult !== null) {
                $onResult($result);
            }
        }

        return $results;
    }
}
