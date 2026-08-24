<?php

namespace E9li\Fire\Tests;

class MediaUrlsTest extends TestCase
{
    public function testExtractsAndDeduplicatesMediaUrls(): void
    {
        $this->app();

        $html = <<<HTML
        <img
            src="https://example.test/media/pages/home/123/img-800x.jpg"
            srcset="https://example.test/media/pages/home/123/img-400x.jpg 400w, https://example.test/media/pages/home/123/img-800x.jpg 800w, https://example.test/media/pages/home/123/img-1200x.jpg 1200w"
        >
        HTML;

        $this->assertSame([
            'https://example.test/media/pages/home/123/img-800x.jpg',
            'https://example.test/media/pages/home/123/img-400x.jpg',
            'https://example.test/media/pages/home/123/img-1200x.jpg',
        ], fireMediaUrls($html));
    }

    public function testPictureSourcesAndDensityDescriptors(): void
    {
        $this->app();

        $html = <<<HTML
        <picture>
            <source srcset="https://example.test/media/pages/a/1/img.webp 1x, https://example.test/media/pages/a/1/img-2x.webp 2x">
            <img src="https://example.test/media/pages/a/1/img.jpg">
        </picture>
        HTML;

        $this->assertSame([
            'https://example.test/media/pages/a/1/img.webp',
            'https://example.test/media/pages/a/1/img-2x.webp',
            'https://example.test/media/pages/a/1/img.jpg',
        ], fireMediaUrls($html));
    }

    public function testIgnoresForeignAndNonMediaUrls(): void
    {
        $this->app();

        $html = <<<HTML
        <img src="https://evil.test/media/pages/x/1/img.jpg">
        <img src="https://example.test/assets/logo.svg">
        <script src="https://example.test/media/plugins/vendor/plugin/index.js"></script>
        HTML;

        // foreign hosts and non-/media/ paths are excluded; same-site
        // plugin assets under /media/ are legitimate warm targets
        $this->assertSame([
            'https://example.test/media/plugins/vendor/plugin/index.js',
        ], fireMediaUrls($html));
    }

    public function testEmptyForHtmlWithoutMedia(): void
    {
        $this->app();

        $this->assertSame([], fireMediaUrls('<h1>Hello</h1>'));
    }
}
