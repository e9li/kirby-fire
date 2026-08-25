<?php

@include_once __DIR__ . '/vendor/autoload.php';

use E9li\Fire\Commands;
use E9li\Fire\Jobs;
use E9li\Fire\Pages;
use E9li\Fire\PagesCache;
use E9li\Fire\Progress;
use E9li\Fire\Renderer;
use E9li\Fire\Urls;
use E9li\Fire\Warmer;
use Kirby\Cms\App;

load([
    'e9li\\fire\\commands' => __DIR__ . '/src/Commands.php',
    'e9li\\fire\\jobs' => __DIR__ . '/src/Jobs.php',
    'e9li\\fire\\pages' => __DIR__ . '/src/Pages.php',
    'e9li\\fire\\pagescache' => __DIR__ . '/src/PagesCache.php',
    'e9li\\fire\\progress' => __DIR__ . '/src/Progress.php',
    'e9li\\fire\\renderer' => __DIR__ . '/src/Renderer.php',
    'e9li\\fire\\urls' => __DIR__ . '/src/Urls.php',
    'e9li\\fire\\warmer' => __DIR__ . '/src/Warmer.php',
]);

App::plugin('e9li/kirby-fire', [
    'options' => [
        'domain' => '',
        'ignore' => [
            'page' => [],
            'template' => [],
            'language' => [],
        ],
        // TLS verification is on by default; set to true for local dev
        // certificates (or pass --insecure to fire:up)
        'insecure' => false,
        'timeout' => 60,
        'concurrency' => 5,
    ],
    'translations' => [
        'en' => require __DIR__ . '/translations/en.php',
        'de' => require __DIR__ . '/translations/de.php',
        'de-ch' => require __DIR__ . '/translations/de-ch.php',
        'fr' => require __DIR__ . '/translations/fr.php',
        'it' => require __DIR__ . '/translations/it.php',
        'ru' => require __DIR__ . '/translations/ru.php',
        'sr' => require __DIR__ . '/translations/sr.php',
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
                'concurrency' => [
                    'description' => 'Requests in flight at once (default 5)',
                    'longPrefix' => 'concurrency',
                    'castTo' => 'int',
                ],
                'insecure' => [
                    'description' => 'Skip TLS certificate verification (local dev certificates)',
                    'longPrefix' => 'insecure',
                    'noValue' => true,
                ],
                'fresh' => [
                    'description' => 'Flush the pages cache before warming',
                    'longPrefix' => 'fresh',
                    'noValue' => true,
                ],
            ],
            'command' => function ($cli): void {

                $cli->br();
                $cli->bold('Fire up the cache...');

                Commands::cacheWarning($cli);

                $skipMedia = $cli->arg('no-media') === true;

                // explicit CLI argument wins over the config option; prompt
                // only as a last resort and only on a terminal, so cron jobs
                // fall through to the site URL instead of hanging
                $domain = $cli->arg('domain')
                    ?: kirby()->option('e9li.kirby-fire.domain')
                    ?: (stream_isatty(STDIN)
                        ? $cli->prompt('Enter the domain to fire up (leave empty for ' . kirby()->url() . '):', false)
                        : '');

                if (empty($domain) === false && filter_var($domain, FILTER_VALIDATE_URL) === false) {
                    $cli->br();
                    $cli->error(' Invalid domain! Use a full URL like https://domain.com ');
                    $cli->br();
                    exit(1);
                }

                if ($cli->arg('fresh') === true) {
                    Commands::freshFlush($cli);
                }

                $concurrency = max(1, (int)($cli->arg('concurrency')
                    ?: kirby()->option('e9li.kirby-fire.concurrency', 5)));
                $client = Warmer::client($cli->arg('insecure') === true ? true : null);

                // resolve every target URL first — the crawl runs with
                // $concurrency requests in flight, so results arrive in
                // completion order and need their per-URL context up front
                $targets = [];
                $skipped = 0;

                foreach (Pages::urls() as $item) {
                    $url = $item['url'];

                    if (empty($domain) === false) {
                        if (($url = Urls::rewrite($url, $domain)) === null) {
                            // e.g. a language living on its own domain — the
                            // URL cannot be mapped onto the target domain
                            $skipped++;
                            continue;
                        }
                    }

                    $targets[$url] = $item['isErrorPage'];
                }

                $pagesOn = 0;
                $mediaOn = 0;
                $failed = 0;
                $mediaQueue = [];

                $progress = new Progress(count($targets), $cli->arg('quiet') !== true);

                Warmer::warmAll(
                    $client,
                    array_keys($targets),
                    $concurrency,
                    true,
                    function (string $url, array $result) use ($cli, $targets, $progress, &$pagesOn, &$failed, &$mediaQueue): void {
                        // the error page renders (and caches) with HTTP 404 by
                        // design — that is a warmed page, not a failure
                        $expected404 = $targets[$url] === true && $result['status'] === 404;

                        if ($result['status'] === 0 || ($result['status'] >= 400 && $expected404 === false)) {
                            $failed++;
                            $progress->error($cli, $url . ' → ' . ($result['error'] ?? 'HTTP ' . $result['status']));
                        } else {
                            $pagesOn++;
                            $progress->advance('fire up ' . $url . ($expected404 ? ' (error page, 404 expected)' : ''));

                            foreach ($result['media'] as $mediaUrl) {
                                $mediaQueue[$mediaUrl] = true;
                            }
                        }
                    }
                );

                $progress->finish();

                if ($skipMedia === false && $mediaQueue !== []) {
                    // status-only requests: the thumb is generated server-side
                    // before the first body byte, so no image is downloaded
                    $mediaProgress = new Progress(count($mediaQueue), $cli->arg('quiet') !== true);

                    Warmer::warmAll(
                        $client,
                        array_keys($mediaQueue),
                        $concurrency,
                        false,
                        function (string $url, array $result) use ($cli, $mediaProgress, &$mediaOn, &$failed): void {
                            if ($result['status'] === 0 || $result['status'] >= 400) {
                                $failed++;
                                $mediaProgress->error($cli, 'media: ' . $url . ' → ' . ($result['error'] ?? 'HTTP ' . $result['status']));
                            } else {
                                $mediaOn++;
                                $mediaProgress->advance('media ' . $url);
                            }
                        }
                    );

                    $mediaProgress->finish();
                }

                $cli->br();

                if ($skipped > 0) {
                    $cli->out($skipped . ' URL(s) skipped — they do not live below ' . kirby()->url() . ' and cannot be rewritten to ' . $domain . '.');
                }

                if ($failed > 0) {
                    $cli->error(' ' . $failed . ' URL(s) failed — see above. ');
                }

                $cli->success(' Cache is on (' . $pagesOn . ' pages, ' . $mediaOn . ' media files), site is ready! ');

                if ($skipMedia === true) {
                    $cli->out('Thumbs were skipped — run "kirby fire:thumbs" to generate them.');
                }

                $cli->br();

                if ($failed > 0) {
                    // cron and CI monitors need the failure to be visible
                    exit(1);
                }
            },
        ],
        'fire:render' => [
            'description' => 'render pages in-process into the cache — no HTTP',
            'args' => [
                'fresh' => [
                    'description' => 'Flush the pages cache before rendering',
                    'longPrefix' => 'fresh',
                    'noValue' => true,
                ],
            ],
            'command' => function ($cli): void {

                $cli->br();
                $cli->bold('Render pages into the cache...');

                Commands::cacheWarning($cli);

                // rendered URLs come from the configured url, not from a
                // request — without the option the cached HTML would link to
                // whatever the CLI environment guesses
                if (empty(kirby()->option('url')) === true) {
                    $cli->out('Note: the url option is not set — URLs in the cached HTML are based on ' . kirby()->url());
                }

                if ($cli->arg('fresh') === true) {
                    Commands::freshFlush($cli);
                }

                $rendered = 0;
                $failed = 0;

                $targets = Pages::targets();
                $progress = new Progress(count($targets), $cli->arg('quiet') !== true);

                Renderer::renderAll(function (array $result) use ($cli, $progress, &$rendered, &$failed): void {
                    if ($result['ok'] === false) {
                        $failed++;
                        $progress->error($cli, $result['url'] . ' → ' . $result['error']);
                    } else {
                        $rendered++;
                        $progress->advance('render ' . $result['url']);
                    }
                }, $targets);

                $progress->finish();

                $cli->br();

                if ($failed > 0) {
                    $cli->error(' ' . $failed . ' page(s) failed — see above. ');
                }

                $cli->success(' ' . $rendered . ' page(s) rendered, cache is on. ');
                $cli->out('Run "kirby fire:thumbs" to generate the queued thumbs.');
                $cli->br();

                if ($failed > 0) {
                    exit(1);
                }
            },
        ],
        'fire:thumbs' => [
            'description' => 'generate pending thumbnails',
            'args' => [
            ],
            'command' => function ($cli): void {

                $cli->br();
                $cli->bold('Render pending thumbs...');

                $jobs = Jobs::all();

                if ($jobs === []) {
                    $cli->br();
                    $cli->success(' Nothing to render — every thumb is already on. ');
                    $cli->out('If images are missing, the pages have to be rendered first: flush the');
                    $cli->out('page cache, then run "kirby fire:render && kirby fire:thumbs".');
                    $cli->br();
                    return;
                }

                $rendered = 0;
                $failed = 0;

                $progress = new Progress(count($jobs), $cli->arg('quiet') !== true);

                foreach ($jobs as $job) {
                    $result = Jobs::thumb($job);

                    if ($result['ok'] === false) {
                        $failed++;
                        $progress->error($cli, $job['path'] . ' → ' . $result['error']);
                    } else {
                        $rendered++;
                        $progress->advance($job['filename']);
                    }
                }

                $progress->finish();

                $cli->br();

                if ($failed > 0) {
                    $cli->error(' ' . $failed . ' thumb(s) failed — see above. ');
                }

                $cli->success(' ' . $rendered . ' thumb(s) rendered ');
                $cli->br();

                if ($failed > 0) {
                    exit(1);
                }
            },
        ],
    ],
    'icons' => [
        'fire' => '<path d="M12 23C16.1421 23 19.5 19.6421 19.5 15.5C19.5 14.6345 19.2697 13.8032 19 13.0296C17.3333 14.6765 16.0667 15.5 15.2 15.5C19.1954 8.5 17 5.5 11 1.5C11.5 6.49951 8.20403 8.77375 6.86179 10.0366C5.40786 11.4045 4.5 13.3462 4.5 15.5C4.5 19.6421 7.85786 23 12 23ZM12.7094 5.23498C15.9511 7.98528 15.9666 10.1223 13.463 14.5086C12.702 15.8419 13.6648 17.5 15.2 17.5C15.8884 17.5 16.5841 17.2992 17.3189 16.9051C16.6979 19.262 14.5519 21 12 21C8.96243 21 6.5 18.5376 6.5 15.5C6.5 13.9608 7.13279 12.5276 8.23225 11.4932C8.35826 11.3747 8.99749 10.8081 9.02477 10.7836C9.44862 10.4021 9.7978 10.0663 10.1429 9.69677C11.3733 8.37932 12.2571 6.91631 12.7094 5.23498Z"></path>',
    ],
    'areas' => [
        'fireView' => function (): array {
            return [
                'label' => t('e9li.kirby-fire.title', 'Fire'),
                'icon' => 'fire',
                'menu' => true,
                'link' => 'fire',
                'views' => [
                    [
                        'pattern' => 'fire',
                        'action' => function () {
                            return [
                                'component' => 'fireView',
                                'title' => t('e9li.kirby-fire.title', 'Fire'),
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

                    foreach (Pages::urls() as $item) {
                        $data[] = [
                            'url' => $item['url'],
                            'language' => $item['language'],
                            // already-cached pages start as warmed — the row
                            // states reflect the server-side cache, not just
                            // what this browser session has crawled
                            'state' => $item['cached'] === true ? 'fire-on' : 'no-fire',
                            // the browser-side crawl counts a 404 from the
                            // error page as warmed, like the CLI does
                            'isErrorPage' => $item['isErrorPage'],
                        ];
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
                    if ($url === '' || Urls::isAllowed($url) === false) {
                        return [
                            'url' => $url,
                            'language' => $language,
                            'state' => 'extinguished',
                            'error' => 'URL not allowed',
                        ];
                    }

                    $result = Warmer::warm(Warmer::client(), $url);

                    // the error page renders (and caches) with HTTP 404 by
                    // design — that is a warmed page, not a failure
                    $expected404 = $result['status'] === 404 && Pages::isErrorPageUrl($url);

                    if ($result['status'] === 0 || ($result['status'] >= 400 && $expected404 === false)) {
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
                'pattern' => 'fire/status',
                'method' => 'GET',
                'action' => function (): array {
                    return PagesCache::status();
                },
            ],
            [
                'pattern' => 'fire/clear',
                'method' => 'POST',
                'action' => function (): array {

                    try {
                        kirby()->cache('pages')->flush();
                        $flushed = ['pages'];
                    } catch (Throwable) {
                        // pages cache not available — nothing to flush
                        $flushed = [];
                    }

                    return [
                        'flushed' => $flushed,
                    ];
                },
            ],
        ],
    ],
]);
