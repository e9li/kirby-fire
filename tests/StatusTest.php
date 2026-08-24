<?php

namespace E9li\Fire\Tests;

class StatusTest extends TestCase
{
    public function testInactiveWithoutCacheConfig(): void
    {
        $this->app();

        $this->assertSame(['active' => false, 'count' => null], fireCacheStatus());
    }

    public function testActiveWithEmptyCache(): void
    {
        $this->app([
            'options' => [
                'cache' => ['pages' => ['active' => true]],
            ],
        ]);

        $this->assertSame(['active' => true, 'count' => 0], fireCacheStatus());
    }

    public function testCountsFileCacheEntries(): void
    {
        $this->app([
            'options' => [
                'cache' => ['pages' => ['active' => true]],
            ],
        ]);

        // writing through the cache API lands in the real file cache root
        kirby()->cache('pages')->set('home.latest.html', 'cached html');
        kirby()->cache('pages')->set('about.latest.html', 'cached html');

        $this->assertSame(['active' => true, 'count' => 2], fireCacheStatus());
    }
}
