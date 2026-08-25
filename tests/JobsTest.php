<?php

namespace E9li\Fire\Tests;

use E9li\Fire\Jobs;
use Kirby\Cms\Page;
use Kirby\Cms\Site;

class JobsTest extends TestCase
{
    private function job(string $path): void
    {
        $file = $this->tmp . '/media/' . $path;

        if (is_dir(dirname($file)) === false) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, '{}');
    }

    public function testEmptyWithoutMediaFolder(): void
    {
        $this->app();

        $this->assertSame([], Jobs::all());
    }

    public function testFindsAndResolvesJobs(): void
    {
        // media/pages/<id>/<hash>/.jobs/<filename>.json
        $this->job('pages/home/abc-123/.jobs/img-400x.jpg.json');
        $this->job('site/def-456/.jobs/logo-100x.png.json');
        $this->job('assets/css/images/ghi-789/.jobs/bg-50x.jpg.json');

        // not jobs: a json outside .jobs, a non-json inside .jobs
        $this->job('pages/home/abc-123/other.json');
        $this->job('pages/home/abc-123/.jobs/img-400x.jpg');

        $this->app();

        $jobs = Jobs::all();
        $this->assertCount(3, $jobs);

        $byPath = array_column($jobs, null, 'path');

        $page = $byPath['pages/home/abc-123/.jobs/img-400x.jpg.json'];
        $this->assertInstanceOf(Page::class, $page['model']);
        $this->assertSame('home', $page['model']->id());
        $this->assertSame('abc-123', $page['hash']);
        $this->assertSame('img-400x.jpg', $page['filename']);

        $site = $byPath['site/def-456/.jobs/logo-100x.png.json'];
        $this->assertInstanceOf(Site::class, $site['model']);
        $this->assertSame('logo-100x.png', $site['filename']);

        // custom assets are addressed by their path relative to the index
        $asset = $byPath['assets/css/images/ghi-789/.jobs/bg-50x.jpg.json'];
        $this->assertSame('css/images', $asset['model']);
        $this->assertSame('ghi-789', $asset['hash']);
    }

    public function testNestedPageIdsSplitFromTheRight(): void
    {
        $this->job('pages/blog/2026/post/abc-123/.jobs/hero-800x.jpg.json');

        $this->app();

        $jobs = Jobs::all();
        $this->assertCount(1, $jobs);

        // blog/2026/post does not exist in the fixtures: the id must still
        // parse correctly and the orphaned job must be reported, not dropped
        $this->assertNull($jobs[0]['model']);
        $this->assertSame('abc-123', $jobs[0]['hash']);
        $this->assertSame('hero-800x.jpg', $jobs[0]['filename']);
    }
}
