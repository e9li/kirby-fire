<?php

namespace E9li\Fire\Tests;

use E9li\Fire\PagesCache;
use E9li\Fire\Renderer;

class RendererTest extends TestCase
{
    private function renderApp(array $props = []): void
    {
        $this->app(array_replace_recursive([
            'roots' => [
                'templates' => __DIR__ . '/fixtures/templates',
            ],
            'options' => [
                'cache' => ['pages' => ['active' => true]],
            ],
        ], $props));
    }

    public function testRendersEveryPageIntoTheCache(): void
    {
        $this->renderApp();

        $results = Renderer::renderAll();

        // fixtures: home, error, about
        $this->assertCount(3, $results);
        $this->assertSame([], array_filter($results, fn (array $r) => $r['ok'] === false));

        // Page::render() must have written the pages cache in-process
        $this->assertSame(3, PagesCache::status()['count']);
    }

    public function testRendersEveryLanguage(): void
    {
        $this->renderApp(['languages' => $this->languages()]);

        $results = Renderer::renderAll();

        // 3 pages × 3 languages, one cache entry each (the cache id
        // contains the language code)
        $this->assertCount(9, $results);
        $this->assertSame([], array_filter($results, fn (array $r) => $r['ok'] === false));
        $this->assertSame(9, PagesCache::status()['count']);
    }

    public function testHasMatchesCoreCacheIds(): void
    {
        // PagesCache::has() mirrors the protected Page::cacheId() format —
        // this closes the loop against entries core actually writes, so a
        // format drift in Kirby fails here instead of going stale silently
        $this->renderApp();
        Renderer::renderAll();

        $this->assertTrue(PagesCache::has(site()->page('home'), null));
    }

    public function testHasMatchesCoreCacheIdsPerLanguage(): void
    {
        $this->renderApp(['languages' => $this->languages()]);
        Renderer::renderAll();

        $home = site()->page('home');
        $this->assertTrue(PagesCache::has($home, 'de'));
        $this->assertTrue(PagesCache::has($home, 'fr'));
        $this->assertFalse(PagesCache::has($home, 'nl'));
    }

    public function testReportsRenderFailuresWithoutAborting(): void
    {
        $this->renderApp([
            'roots' => [
                'content' => __DIR__ . '/fixtures/content-broken',
            ],
        ]);

        $onResult = 0;
        $results = Renderer::renderAll(function () use (&$onResult): void {
            $onResult++;
        });

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['ok']);
        $this->assertSame('template exploded', $results[0]['error']);
        $this->assertSame(1, $onResult);
    }
}
