<?php

namespace E9li\Fire\Tests;

use E9li\Fire\Urls;

class UrlsTest extends TestCase
{
    public function testAllowedUrlsOnSingleLanguageSite(): void
    {
        $this->app();

        // the home page URL is the base without a trailing slash
        $this->assertTrue(Urls::isAllowed(self::BASE));
        $this->assertTrue(Urls::isAllowed(self::BASE . '/'));
        $this->assertTrue(Urls::isAllowed(self::BASE . '/about'));
        $this->assertTrue(Urls::isAllowed(self::BASE . '/media/pages/home/123/img.jpg'));

        // SSRF guard: same-prefix hosts and foreign hosts stay blocked
        $this->assertFalse(Urls::isAllowed(self::BASE . '.evil.com/'));
        $this->assertFalse(Urls::isAllowed('https://other.test/about'));
    }

    public function testAllowedBasesIncludeDomainOption(): void
    {
        $this->app([
            'options' => [
                'e9li.kirby-fire' => ['domain' => 'https://live.example.com'],
            ],
        ]);

        $this->assertContains('https://live.example.com/', Urls::allowedBases());
        $this->assertTrue(Urls::isAllowed('https://live.example.com/about'));
    }

    public function testAllowedBasesIncludeLanguageDomains(): void
    {
        $this->app(['languages' => $this->languages()]);

        $this->assertContains('https://fr.example.test/', Urls::allowedBases());
        $this->assertTrue(Urls::isAllowed('https://fr.example.test'));
        $this->assertTrue(Urls::isAllowed('https://fr.example.test/about'));
        $this->assertFalse(Urls::isAllowed('https://fr.example.test.evil.com/'));
    }

    public function testRewriteUrl(): void
    {
        $this->app();

        // the home page maps onto the bare target domain
        $this->assertSame('https://live.com', Urls::rewrite(self::BASE, 'https://live.com'));
        $this->assertSame('https://live.com', Urls::rewrite(self::BASE, 'https://live.com/'));
        $this->assertSame('https://live.com/a/b', Urls::rewrite(self::BASE . '/a/b', 'https://live.com'));

        // URLs outside the site base cannot be mapped
        $this->assertNull(Urls::rewrite('https://foreign.test/a', 'https://live.com'));
        $this->assertNull(Urls::rewrite(self::BASE . '.evil.com/a', 'https://live.com'));
    }
}
