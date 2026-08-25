<?php

namespace E9li\Fire;

/**
 * URL guarding and extraction: which URLs the warmer may request, how they
 * map onto another domain, and which media URLs an HTML document references.
 */
class Urls
{
    /**
     * Base URLs the warmer is allowed to request: the site's own URL, the
     * optional domain override and every language's own domain. Guards the
     * fire/up API route against being used to fetch arbitrary third-party
     * (or internal) URLs.
     */
    public static function allowedBases(): array
    {
        $bases = [rtrim(kirby()->url(), '/') . '/'];

        if ($domain = kirby()->option('e9li.kirby-fire.domain')) {
            $bases[] = rtrim($domain, '/') . '/';
        }

        // languages may live on their own domains via their url option
        foreach (kirby()->languages() as $language) {
            $bases[] = rtrim($language->baseUrl(), '/') . '/';
        }

        return array_values(array_unique($bases));
    }

    public static function isAllowed(string $url): bool
    {
        foreach (static::allowedBases() as $base) {
            // The home page URL is the base itself, without the trailing
            // slash. Every other URL must match the slash-suffixed prefix,
            // so that https://example.com never allows
            // https://example.com.evil.com
            if ($url === rtrim($base, '/') || str_starts_with($url, $base)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rebuilds a page URL for a different domain, so the CLI can warm the
     * live domain while running anywhere. Returns null when the URL does
     * not belong to the site.
     */
    public static function rewrite(string $url, string $domain): ?string
    {
        $siteBase = rtrim(kirby()->url(), '/');

        // the home page URL is the site base itself, without a trailing slash
        if ($url === $siteBase) {
            return rtrim($domain, '/');
        }

        if (str_starts_with($url, $siteBase . '/') === false) {
            return null;
        }

        return rtrim($domain, '/') . substr($url, strlen($siteBase));
    }

    /**
     * Extracts same-site media URLs (thumbs) from src/srcset attributes of
     * an HTML document. Fetching them lets Kirby's media route generate the
     * thumbs, so a crawl also warms the thumb cache.
     */
    public static function media(string $html): array
    {
        preg_match_all('/(?:src|srcset)="([^"]+)"/i', $html, $matches);

        $urls = [];

        foreach ($matches[1] as $attribute) {
            // srcset is a comma-separated list of "url descriptor" candidates
            foreach (explode(',', $attribute) as $candidate) {
                $url = preg_split('/\s+/', trim($candidate))[0] ?? '';

                if (str_contains($url, '/media/') && static::isAllowed($url)) {
                    $urls[$url] = true;
                }
            }
        }

        return array_keys($urls);
    }
}
