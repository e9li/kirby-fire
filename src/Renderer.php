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
    public static function renderAll(?callable $onResult = null): array
    {
        $kirby = kirby();
        $results = [];

        foreach (Pages::targets() as $target) {
            if ($kirby->multilang() === true && $target['language'] !== null) {
                $kirby->setCurrentLanguage($target['language']);
            }

            try {
                $target['page']->render();
                $result = ['url' => $target['url'], 'ok' => true, 'error' => null];
            } catch (\Throwable $e) {
                $result = ['url' => $target['url'], 'ok' => false, 'error' => $e->getMessage()];
            }

            $results[] = $result;

            if ($onResult !== null) {
                $onResult($result);
            }
        }

        return $results;
    }
}
