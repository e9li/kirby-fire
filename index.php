<?php

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;

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
                    'description' => 'Domain to fire up the cache',
                ]
            ],
            'command' => function ($cli): void {

                $cli->br();
                $cli->bold('🔥 fire up the cache...');

                $domain = kirby()->option('e9li.kirby-fire.domain');

                if (empty($domain)) {
                    $domain = $cli->argOrPrompt('domain', 'Enter the domain to fire up the cache (e.g. https://domain.com):', true);
                }

                if (empty($domain) || filter_var($domain, FILTER_VALIDATE_DOMAIN) === false) {
                    $cli->br();
                    $cli->error(' No domain provided! ');
                    $cli->br();
                    exit();
                }

                $pages = site()->pages()->index();

                $i = 1;

                foreach ($pages as $page) {
                    foreach (kirby()->languages() as $language) {
                        $url = $domain . $page->url($language->code());
                        $cli->out($i . ': fire up ' . $url);
                        $browser = new HttpBrowser(HttpClient::create(['verify_peer' => false, 'verify_host' => false]));
                        $browser->request('GET', $url);
                        $i++;
                    }
                    $i++;
                }

                $cli->br();
                $cli->success(' Cache is on 🔥, site is ready! ');
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
                    $pages = site()->pages()->index();

                    foreach ($pages as $page) {
                        foreach (kirby()->languages() as $language) {
                            $languageCode = $language->code();
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

                    $url = $this->requestBody('url');
                    $language = $this->requestBody('language');


                    if ($url) {
                        $browser = new HttpBrowser(HttpClient::create(['verify_peer' => false, 'verify_host' => false]));
                        $browser->request('GET', $url);

                        return [
                            'url' => $url,
                            'language' => $language,
                            'state' => 'fire-on',
                        ];
                    }

                    return [
                        'url' => $url,
                        'language' => $language,
                        'state' => 'extinguished',
                    ];

                },
            ],
        ],
    ],
]);
