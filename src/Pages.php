<?php

namespace E9li\Fire;

/**
 * Every page × language combination the warmer should visit, with the
 * configured ignore rules applied.
 */
class Pages
{
    /**
     * Page targets with their page object, per-language URL and error page
     * flag. On single-language sites the language is null:
     * kirby()->languages() is empty there, so iterating it directly would
     * silently skip every page.
     */
    public static function targets(): array
    {
        $ignorePages = (array)kirby()->option('e9li.kirby-fire.ignore.page', []);
        $ignoreTemplates = (array)kirby()->option('e9li.kirby-fire.ignore.template', []);
        $ignoreLanguages = (array)kirby()->option('e9li.kirby-fire.ignore.language', []);

        $languages = kirby()->languages();
        $languages = $languages->count() > 0 ? $languages : [null];

        $targets = [];

        foreach (site()->pages()->index() as $page) {
            $template = $page->intendedTemplate()->name();

            // template ignores exist for whole page classes that cannot be
            // warmed — typically redirect templates, whose go() call ends
            // the process during an in-process render
            if (
                in_array($page->id(), $ignorePages, true) ||
                in_array($template, $ignoreTemplates, true)
            ) {
                continue;
            }

            foreach ($languages as $language) {
                $code = $language?->code();

                if ($code !== null && in_array($code, $ignoreLanguages, true)) {
                    continue;
                }

                $targets[] = [
                    'page' => $page,
                    'url' => $page->url($code),
                    'language' => $code,
                    'template' => $template,
                    // the error page answers with HTTP 404 by design — HTTP
                    // callers must count that as warmed, not as a failure
                    'isErrorPage' => $page->isErrorPage(),
                ];
            }
        }

        return $targets;
    }

    /**
     * URL list form of targets(), for the HTTP crawl and the fire/pages
     * API route.
     */
    public static function urls(): array
    {
        return array_map(fn (array $target) => [
            'url' => $target['url'],
            'language' => $target['language'],
            'isErrorPage' => $target['isErrorPage'],
        ], static::targets());
    }

    /**
     * Whether a URL is the error page in any language. Lets the fire/up API
     * route apply the 404-is-expected rule without trusting a client flag.
     */
    public static function isErrorPageUrl(string $url): bool
    {
        if (($error = site()->errorPage()) === null) {
            return false;
        }

        $languages = kirby()->languages();
        $languages = $languages->count() > 0 ? $languages : [null];

        foreach ($languages as $language) {
            if ($url === $error->url($language?->code())) {
                return true;
            }
        }

        return false;
    }
}
