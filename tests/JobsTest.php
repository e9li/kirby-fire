<?php

namespace E9li\Fire\Tests;

use E9li\Fire\Jobs;

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

    public function testFindsAndParsesJobs(): void
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
        $this->assertSame('pages', $page['type']);
        $this->assertSame('home', $page['id']);
        $this->assertSame('abc-123', $page['hash']);
        $this->assertSame('img-400x.jpg', $page['filename']);

        $site = $byPath['site/def-456/.jobs/logo-100x.png.json'];
        $this->assertSame('site', $site['type']);
        $this->assertSame('logo-100x.png', $site['filename']);

        // custom assets are addressed by their path relative to the index
        $asset = $byPath['assets/css/images/ghi-789/.jobs/bg-50x.jpg.json'];
        $this->assertSame('assets', $asset['type']);
        $this->assertSame('css/images', $asset['id']);
        $this->assertSame('ghi-789', $asset['hash']);
    }

    public function testNestedPageIdsSplitFromTheRight(): void
    {
        $this->job('pages/blog/2026/post/abc-123/.jobs/hero-800x.jpg.json');

        $this->app();

        $jobs = Jobs::all();
        $this->assertCount(1, $jobs);
        $this->assertSame('pages', $jobs[0]['type']);
        $this->assertSame('blog/2026/post', $jobs[0]['id']);
        $this->assertSame('abc-123', $jobs[0]['hash']);
        $this->assertSame('hero-800x.jpg', $jobs[0]['filename']);
    }

    public function testThumbReportsOrphanedJobs(): void
    {
        $this->app();

        // blog/2026/post does not exist in the fixtures: the orphaned job
        // must be reported, not dropped
        $result = Jobs::thumb([
            'type' => 'pages',
            'id' => 'blog/2026/post',
            'hash' => 'abc-123',
            'filename' => 'hero-800x.jpg',
            'path' => 'pages/blog/2026/post/abc-123/.jobs/hero-800x.jpg.json',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('no page, site or user owns this job any more', $result['error']);
    }

    public function testThumbResolvesExistingModelsLazily(): void
    {
        $this->app();

        // home exists: the job fails in core (no real job file), but with a
        // media error — not the orphan message — proving the model resolved
        $result = Jobs::thumb([
            'type' => 'pages',
            'id' => 'home',
            'hash' => 'abc-123',
            'filename' => 'img-400x.jpg',
            'path' => 'pages/home/abc-123/.jobs/img-400x.jpg.json',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertNotSame('no page, site or user owns this job any more', $result['error']);
    }
}
