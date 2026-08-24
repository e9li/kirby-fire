<?php

namespace E9li\Fire\Tests;

class UrlsTest extends TestCase
{
    public function testAllowedUrlsOnSingleLanguageSite(): void
    {
        $this->app();

        // the home page URL is the base without a trailing slash
        $this->assertTrue(fireAllowedUrl(self::BASE));
        $this->assertTrue(fireAllowedUrl(self::BASE . '/'));
        $this->assertTrue(fireAllowedUrl(self::BASE . '/about'));
        $this->assertTrue(fireAllowedUrl(self::BASE . '/media/pages/home/123/img.jpg'));

        // SSRF guard: same-prefix hosts and foreign hosts stay blocked
        $this->assertFalse(fireAllowedUrl(self::BASE . '.evil.com/'));
        $this->assertFalse(fireAllowedUrl('https://other.test/about'));
    }

    public function testAllowedBasesIncludeDomainOption(): void
    {
        $this->app([
            'options' => [
                'e9li.kirby-fire' => ['domain' => 'https://live.example.com'],
            ],
        ]);

        $this->assertContains('https://live.example.com/', fireAllowedBases());
        $this->assertTrue(fireAllowedUrl('https://live.example.com/about'));
    }

    public function testAllowedBasesIncludeLanguageDomains(): void
    {
        $this->app(['languages' => $this->languages()]);

        $this->assertContains('https://fr.example.test/', fireAllowedBases());
        $this->assertTrue(fireAllowedUrl('https://fr.example.test'));
        $this->assertTrue(fireAllowedUrl('https://fr.example.test/about'));
        $this->assertFalse(fireAllowedUrl('https://fr.example.test.evil.com/'));
    }

    public function testRewriteUrl(): void
    {
        $this->app();

        // the home page maps onto the bare target domain
        $this->assertSame('https://live.com', fireRewriteUrl(self::BASE, 'https://live.com'));
        $this->assertSame('https://live.com', fireRewriteUrl(self::BASE, 'https://live.com/'));
        $this->assertSame('https://live.com/a/b', fireRewriteUrl(self::BASE . '/a/b', 'https://live.com'));

        // URLs outside the site base cannot be mapped
        $this->assertNull(fireRewriteUrl('https://foreign.test/a', 'https://live.com'));
        $this->assertNull(fireRewriteUrl(self::BASE . '.evil.com/a', 'https://live.com'));
    }
}
