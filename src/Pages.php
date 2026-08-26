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
                static::isIgnoredId($page->id(), $ignorePages) ||
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
     * Whether a page id matches the ignore rules. Plain entries match
     * exactly; a trailing "/*" ignores a whole branch — the page itself
     * and everything below it ("data/*" skips data, data/forms,
     * data/forms/entry, …).
     */
    public static function isIgnoredId(string $id, array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule === $id) {
                return true;
            }

            if (str_ends_with((string)$rule, '/*') === true) {
                $branch = substr($rule, 0, -2);

                if ($id === $branch || str_starts_with($id, $branch . '/') === true) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * URL list form of targets(), for the HTTP crawl and the fire/pages
     * API route. Each entry knows whether the page is already cached, so
     * the Panel can show the real server-side state instead of starting
     * every row at "no fire".
     */
    public static function urls(): array
    {
        return array_map(fn (array $target) => [
            'url' => $target['url'],
            'id' => $target['page']->id(),
            'language' => $target['language'],
            'isErrorPage' => $target['isErrorPage'],
            'cached' => PagesCache::has($target['page'], $target['language']),
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
