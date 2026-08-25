<?php

namespace E9li\Fire\Tests;

use E9li\Fire\Pages;

class PageUrlsTest extends TestCase
{
    public function testSingleLanguageSiteYieldsEveryPage(): void
    {
        $this->app();

        $urls = Pages::urls();

        // fixtures: home, error, about — with no languages folder the
        // language must fall back to null instead of skipping every page
        $this->assertCount(3, $urls);
        $this->assertSame([null], array_unique(array_column($urls, 'language')));

        $byUrl = array_column($urls, null, 'url');
        $this->assertArrayHasKey(self::BASE, $byUrl); // home, no trailing slash
        $this->assertArrayHasKey(self::BASE . '/about', $byUrl);
        $this->assertArrayHasKey(self::BASE . '/error', $byUrl);
    }

    public function testErrorPageIsFlagged(): void
    {
        $this->app();

        $flagged = array_values(array_filter(Pages::urls(), fn ($u) => $u['isErrorPage']));

        $this->assertCount(1, $flagged);
        $this->assertSame(self::BASE . '/error', $flagged[0]['url']);
    }

    public function testIgnoredPagesAndLanguagesAreSkipped(): void
    {
        $this->app([
            'languages' => $this->languages(),
            'options' => [
                'e9li.kirby-fire' => [
                    'ignore' => [
                        'page' => ['about'],
                        'language' => ['de'],
                    ],
                ],
            ],
        ]);

        $urls = Pages::urls();

        $this->assertSame(['en', 'fr'], array_values(array_unique(array_column($urls, 'language'))));
        $this->assertNotContains(self::BASE . '/about', array_column($urls, 'url'));
        $this->assertCount(4, $urls); // home + error, in en + fr
    }

    public function testMultilangUrlsPerLanguage(): void
    {
        $this->app(['languages' => $this->languages()]);

        $urls = Pages::urls();
        $byLanguage = [];

        foreach ($urls as $item) {
            $byLanguage[$item['language']][] = $item['url'];
        }

        $this->assertSame(['en', 'de', 'fr'], array_keys($byLanguage));
        // default language at "/": home is the bare base URL
        $this->assertContains(self::BASE, $byLanguage['en']);
        $this->assertContains(self::BASE . '/de', $byLanguage['de']);
        // language on its own domain
        $this->assertContains('https://fr.example.test', $byLanguage['fr']);
        $this->assertContains('https://fr.example.test/about', $byLanguage['fr']);

        // every language flags its error page
        $flagged = array_filter($urls, fn ($u) => $u['isErrorPage']);
        $this->assertCount(3, $flagged);
    }

    public function testIsErrorPageUrl(): void
    {
        $this->app(['languages' => $this->languages()]);

        $this->assertTrue(Pages::isErrorPageUrl(self::BASE . '/error'));
        $this->assertTrue(Pages::isErrorPageUrl(self::BASE . '/de/error'));
        $this->assertTrue(Pages::isErrorPageUrl('https://fr.example.test/error'));

        $this->assertFalse(Pages::isErrorPageUrl(self::BASE));
        $this->assertFalse(Pages::isErrorPageUrl(self::BASE . '/about'));
        $this->assertFalse(Pages::isErrorPageUrl('https://other.test/error'));
    }

    public function testPagesCacheDetection(): void
    {
        // the fire:up preflight warning fires on exactly this expression
        $this->app();
        $this->assertFalse(kirby()->cache('pages')->options()['active'] ?? false);

        $this->app([
            'options' => [
                'cache' => ['pages' => ['active' => true]],
            ],
        ]);
        $this->assertTrue(kirby()->cache('pages')->options()['active'] ?? false);
    }
}
