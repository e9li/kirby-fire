<?php

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App;
use Kirby\Cms\Media;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Shared browser: follows redirects so redirected pages warm their target,
 * and disables peer/host verification for local dev certificates.
 */
function fireBrowser(): HttpBrowser
{
    $browser = new HttpBrowser(HttpClient::create([
        'verify_peer' => false,
        'verify_host' => false,
        'timeout' => 60,
    ]));
    $browser->followRedirects(true);

    return $browser;
}

/**
 * Base URLs the warmer is allowed to request: the site's own URL plus the
 * optional domain override. Guards the fire/up API route against being used
 * to fetch arbitrary third-party (or internal) URLs.
 */
function fireAllowedBases(): array
{
    $bases = [rtrim(kirby()->url(), '/') . '/'];

    if ($domain = kirby()->option('e9li.kirby-fire.domain')) {
        $bases[] = rtrim($domain, '/') . '/';
    }

    return $bases;
}

function fireAllowedUrl(string $url): bool
{
    foreach (fireAllowedBases() as $base) {
        if (str_starts_with($url, $base)) {
            return true;
        }
    }

    return false;
}

/**
 * Rebuilds a page URL for a different domain, so the CLI can warm the live
 * domain while running anywhere. Returns null when the URL does not belong
 * to the site.
 */
function fireRewriteUrl(string $url, string $domain): ?string
{
    $siteBase = rtrim(kirby()->url(), '/') . '/';

    if (str_starts_with($url, $siteBase) === false) {
        return null;
    }

    return rtrim($domain, '/') . '/' . substr($url, strlen($siteBase));
}

/**
 * Extracts same-site media URLs (thumbs) from src/srcset attributes of an
 * HTML document. Fetching them lets Kirby's media route generate the thumbs,
 * so a crawl also warms the thumb cache.
 */
function fireMediaUrls(string $html): array
{
    preg_match_all('/(?:src|srcset)="([^"]+)"/i', $html, $matches);

    $urls = [];

    foreach ($matches[1] as $attribute) {
        // srcset is a comma-separated list of "url descriptor" candidates
        foreach (explode(',', $attribute) as $candidate) {
            $url = preg_split('/\s+/', trim($candidate))[0] ?? '';

            if (str_contains($url, '/media/') && fireAllowedUrl($url)) {
                $urls[$url] = true;
            }
        }
    }

    return array_keys($urls);
}

/**
 * Requests one URL and reports the outcome. Media URLs of HTML responses
 * are returned so callers can warm them as well.
 */
function fireWarm(HttpBrowser $browser, string $url): array
{
    try {
        $browser->request('GET', $url);
        $response = $browser->getResponse();
        $status = $response->getStatusCode();
        $content = (string)$response->getContent();
    } catch (Throwable $e) {
        return [
            'status' => 0,
            'error' => $e->getMessage(),
            'media' => [],
        ];
    }

    $isHtml = str_contains($response->getHeader('Content-Type') ?? '', 'text/html');

    return [
        'status' => $status,
        'error' => null,
        'media' => $status < 400 && $isHtml ? fireMediaUrls($content) : [],
    ];
}

/**
 * Every thumbnail Kirby has been asked for but has not generated yet.
 *
 * Rendering a page does not create thumbs — it writes one job file per thumb
 * and leaves the image itself to the media route, which runs the darkroom the
 * first time a browser asks for the URL. Collecting the jobs lets the CLI
 * generate them in-process instead of paying an HTTP round-trip and a full
 * response body per thumb.
 */
function fireJobs(): array
{
    $media = kirby()->root('media');

    if (is_dir($media) === false) {
        return [];
    }

    // Streamed rather than Dir::index($media, true): the media folder holds one
    // file per thumb per size, so indexing it up front would build an array of
    // every generated image just to find the handful of pending jobs.
    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($media, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $jobs = [];

    foreach ($tree as $file) {
        if ($file->isFile() === false || $file->getExtension() !== 'json') {
            continue;
        }

        $path = substr($file->getPathname(), strlen($media) + 1);

        // <type>/<id…>/<hash>/.jobs/<thumb filename>.json — the id can itself
        // contain slashes (page ids do), so split from the right.
        $parts = explode('/', $path);

        if (count($parts) < 4 || $parts[count($parts) - 2] !== '.jobs') {
            continue;
        }

        $filename = substr(array_pop($parts), 0, -5);
        array_pop($parts); // .jobs
        $hash = array_pop($parts);
        $type = array_shift($parts);
        $id = implode('/', $parts);

        $model = match ($type) {
            'pages' => kirby()->page($id),
            'site' => site(),
            'users' => kirby()->user($id),
            // custom assets are addressed by their path relative to the index
            'assets' => $id,
            default => null,
        };

        // A null model means the page/user was deleted but its media folder
        // stayed behind. Reported rather than skipped: silently dropping them
        // would read as "everything rendered" while images stay missing.
        $jobs[] = [
            'model' => $model,
            'hash' => $hash,
            'filename' => $filename,
            'path' => $path,
        ];
    }

    return $jobs;
}

/**
 * Generates one pending thumbnail. Media::thumb() reads the job, resolves the
 * source file, runs the darkroom and removes the job file — reimplementing it
 * here would mean duplicating its path-traversal guards and drifting from core.
 */
function fireThumb(array $job): array
{
    if ($job['model'] === null) {
        return ['ok' => false, 'error' => 'no page, site or user owns this job any more'];
    }

    try {
        Media::thumb($job['model'], $job['hash'], $job['filename']);

        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

App::plugin('e9li/kirby-fire', [
    'options' => [
        'domain' => '',
        'ignore' => [
            'page' => [],
            'language' => [],
        ],
    ],
    'commands' => [
        'fire:up' => [
            'description' => 'generate page cache',
            'args' => [
                'domain' => [
                    'description' => 'Domain to fire up the cache (defaults to the site URL)',
                ],
                'no-media' => [
                    'description' => 'Skip fetching thumbs over HTTP — pair with fire:thumbs',
                    // domain is positional, so this has to be a flag or it would
                    // swallow the domain argument
                    'longPrefix' => 'no-media',
                    'noValue' => true,
                ],
            ],
            'command' => function ($cli): void {

                $cli->br();
                $cli->bold('🔥 fire up the cache...');

                $skipMedia = $cli->arg('no-media') === true;

                $domain = kirby()->option('e9li.kirby-fire.domain')
                    ?: $cli->argOrPrompt('domain', 'Enter the domain to fire up (leave empty for ' . kirby()->url() . '):', false);

                if (empty($domain) === false && filter_var($domain, FILTER_VALIDATE_URL) === false) {
                    $cli->br();
                    $cli->error(' Invalid domain! Use a full URL like https://domain.com ');
                    $cli->br();
                    exit(1);
                }

                $ignorePages = (array)kirby()->option('e9li.kirby-fire.ignore.page', []);
                $ignoreLanguages = (array)kirby()->option('e9li.kirby-fire.ignore.language', []);
                $browser = fireBrowser();

                $pagesOn = 0;
                $mediaOn = 0;
                $failed = 0;
                $i = 1;

                foreach (site()->pages()->index() as $page) {
                    if (in_array($page->id(), $ignorePages, true)) {
                        continue;
                    }

                    foreach (kirby()->languages() as $language) {
                        if (in_array($language->code(), $ignoreLanguages, true)) {
                            continue;
                        }

                        $url = $page->url($language->code());

                        if (empty($domain) === false) {
                            if (($url = fireRewriteUrl($url, $domain)) === null) {
                                continue;
                            }
                        }

                        $result = fireWarm($browser, $url);

                        if ($result['status'] >= 400 || $result['status'] === 0) {
                            $cli->error($i . ': ' . $url . ' → ' . ($result['error'] ?? 'HTTP ' . $result['status']));
                            $failed++;
                            $i++;
                            continue;
                        }

                        $cli->out($i . ': fire up ' . $url);
                        $pagesOn++;
                        $i++;

                        foreach ($skipMedia ? [] : $result['media'] as $mediaUrl) {
                            $mediaResult = fireWarm($browser, $mediaUrl);

                            if ($mediaResult['status'] >= 400 || $mediaResult['status'] === 0) {
                                $cli->error('   media: ' . $mediaUrl . ' → ' . ($mediaResult['error'] ?? 'HTTP ' . $mediaResult['status']));
                                $failed++;
                            } else {
                                $mediaOn++;
                            }
                        }
                    }
                }

                $cli->br();

                if ($failed > 0) {
                    $cli->error(' ' . $failed . ' URL(s) failed — see above. ');
                }

                $cli->success(' Cache is on 🔥 (' . $pagesOn . ' pages, ' . $mediaOn . ' media files), site is ready! ');

                if ($skipMedia === true) {
                    $cli->out('Thumbs were skipped — run "kirby fire:thumbs" to generate them.');
                }

                $cli->br();
            },
        ],
        'fire:thumbs' => [
            'description' => 'generate pending thumbnails',
            'command' => function ($cli): void {

                $cli->br();
                $cli->bold('🔥 render pending thumbs...');

                $jobs = fireJobs();

                if ($jobs === []) {
                    $cli->br();
                    $cli->success(' Nothing to render — every thumb is already on 🔥 ');
                    $cli->out('If images are missing, the pages have to be rendered first: flush the');
                    $cli->out('page cache, then run "kirby fire:up --no-media && kirby fire:thumbs".');
                    $cli->br();
                    return;
                }

                $rendered = 0;
                $failed = 0;
                $i = 1;

                foreach ($jobs as $job) {
                    $result = fireThumb($job);

                    if ($result['ok'] === false) {
                        $cli->error($i . ': ' . $job['path'] . ' → ' . $result['error']);
                        $failed++;
                    } else {
                        $cli->out($i . ': ' . $job['filename']);
                        $rendered++;
                    }

                    $i++;
                }

                $cli->br();

                if ($failed > 0) {
                    $cli->error(' ' . $failed . ' thumb(s) failed — see above. ');
                }

                $cli->success(' ' . $rendered . ' thumb(s) rendered 🔥 ');
                $cli->br();
            },
        ],
    ],
    'icons' => [
        'fire' => '<path d="M12 23C16.1421 23 19.5 19.6421 19.5 15.5C19.5 14.6345 19.2697 13.8032 19 13.0296C17.3333 14.6765 16.0667 15.5 15.2 15.5C19.1954 8.5 17 5.5 11 1.5C11.5 6.49951 8.20403 8.77375 6.86179 10.0366C5.40786 11.4045 4.5 13.3462 4.5 15.5C4.5 19.6421 7.85786 23 12 23ZM12.7094 5.23498C15.9511 7.98528 15.9666 10.1223 13.463 14.5086C12.702 15.8419 13.6648 17.5 15.2 17.5C15.8884 17.5 16.5841 17.2992 17.3189 16.9051C16.6979 19.262 14.5519 21 12 21C8.96243 21 6.5 18.5376 6.5 15.5C6.5 13.9608 7.13279 12.5276 8.23225 11.4932C8.35826 11.3747 8.99749 10.8081 9.02477 10.7836C9.44862 10.4021 9.7978 10.0663 10.1429 9.69677C11.3733 8.37932 12.2571 6.91631 12.7094 5.23498Z"></path>',
    ],
    'areas' => [
        'fireView' => function (): array {
            return [
                'label' => 'Fire',
                'icon' => 'fire',
                'menu' => true,
                'link' => 'fire',
                'views' => [
                    [
                        'pattern' => 'fire',
                        'action' => function () {
                            return [
                                'component' => 'fireView',
                                'title' => 'Fire',
                            ];
                        },
                    ],
                ],
            ];
        },
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'fire/pages',
                'method' => 'GET',
                'action' => function (): array {

                    $data = [];
                    $ignorePages = (array)kirby()->option('e9li.kirby-fire.ignore.page', []);
                    $ignoreLanguages = (array)kirby()->option('e9li.kirby-fire.ignore.language', []);
                    $pages = site()->pages()->index();

                    foreach ($pages as $page) {
                        if (in_array($page->id(), $ignorePages, true)) {
                            continue;
                        }

                        foreach (kirby()->languages() as $language) {
                            $languageCode = $language->code();

                            if (in_array($languageCode, $ignoreLanguages, true)) {
                                continue;
                            }

                            $data[] = [
                                'url' => $page->url($languageCode),
                                'language' => $languageCode,
                                'state' => 'no-fire',
                            ];
                        }
                    }

                    return $data;
                },
            ],
            [
                'pattern' => 'fire/up',
                'method' => 'POST',
                'action' => function (): array {

                    $url = (string)$this->requestBody('url');
                    $language = $this->requestBody('language');

                    // only same-site URLs may be fetched (SSRF guard)
                    if ($url === '' || fireAllowedUrl($url) === false) {
                        return [
                            'url' => $url,
                            'language' => $language,
                            'state' => 'extinguished',
                            'error' => 'URL not allowed',
                        ];
                    }

                    $result = fireWarm(fireBrowser(), $url);

                    if ($result['status'] >= 400 || $result['status'] === 0) {
                        return [
                            'url' => $url,
                            'language' => $language,
                            'state' => 'extinguished',
                            'error' => $result['error'] ?? 'HTTP ' . $result['status'],
                        ];
                    }

                    return [
                        'url' => $url,
                        'language' => $language,
                        'state' => 'fire-on',
                        'media' => $result['media'],
                    ];
                },
            ],
            [
                'pattern' => 'fire/clear',
                'method' => 'POST',
                'action' => function (): array {

                    $flushed = [];

                    foreach (['pages'] as $cache) {
                        try {
                            kirby()->cache($cache)->flush();
                            $flushed[] = $cache;
                        } catch (Throwable) {
                            // cache type not available — nothing to flush
                        }
                    }

                    return [
                        'flushed' => $flushed,
                    ];
                },
            ],
        ],
    ],
]);
