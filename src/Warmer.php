<?php

namespace E9li\Fire;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The HTTP engine: a shared client and the blocking and concurrent warm
 * loops built on it.
 */
class Warmer
{
    /**
     * Shared HTTP client. Follows redirects (client default) so redirected
     * pages warm their target, verifies TLS unless the insecure option (or
     * --insecure) says otherwise, and applies the configured timeout per
     * request.
     */
    public static function client(?bool $insecure = null): HttpClientInterface
    {
        $insecure ??= kirby()->option('e9li.kirby-fire.insecure') === true;

        return HttpClient::create([
            'timeout' => static::timeout(),
            'verify_peer' => $insecure === false,
            'verify_host' => $insecure === false,
        ]);
    }

    /**
     * Per-request timeout. In web context (the Panel's fallback route) it is
     * capped below PHP's max_execution_time, so an unreachable or slow
     * target produces a clean per-URL error instead of the request being
     * killed with a fatal — the common failure on shared hosting, where
     * execution limits are low and loopback requests often hang.
     */
    public static function timeout(): float
    {
        $configured = (float)kirby()->option('e9li.kirby-fire.timeout', 60);

        if (PHP_SAPI === 'cli') {
            return $configured;
        }

        $elapsed = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        return static::cappedTimeout($configured, (int)ini_get('max_execution_time'), $elapsed);
    }

    /**
     * Web requests die hard at max_execution_time; leave headroom so the
     * request can still answer with an error.
     */
    public static function cappedTimeout(float $configured, int $limit, float $elapsed): float
    {
        if ($limit <= 0) {
            return $configured;
        }

        $budget = $limit - $elapsed - 5.0;

        return max(3.0, min($configured, $budget));
    }

    /**
     * Requests one URL and reports the outcome. Media URLs of HTML responses
     * are returned for every response — also a 404, whose body is the
     * rendered error page and may be image-rich. Callers only warm the media
     * of pages they count as successful. Transport errors are retried once.
     */
    public static function warm(HttpClientInterface $client, string $url): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $client->request('GET', $url);
                $status = $response->getStatusCode();
                // no throwing on 4xx/5xx — a 404 body is the rendered error page
                $content = $response->getContent(false);
                $type = $response->getHeaders(false)['content-type'][0] ?? '';
            } catch (\Throwable $e) {
                if ($attempt === 1) {
                    continue;
                }

                return [
                    'status' => 0,
                    'error' => $e->getMessage(),
                    'media' => [],
                ];
            }

            return [
                'status' => $status,
                'error' => null,
                'media' => str_contains($type, 'text/html') ? Urls::media($content) : [],
            ];
        }
    }

    /**
     * Warms many URLs with up to $concurrency requests in flight. With
     * $bodies the responses are downloaded and same-site media URLs
     * extracted (pages); without, every request is cancelled once the status
     * line arrives (media — the thumb is generated before the first body
     * byte, so downloading it would only burn bandwidth). Transport errors
     * are retried once. $onResult runs per finished URL, in completion order.
     */
    public static function warmAll(
        HttpClientInterface $client,
        array $urls,
        int $concurrency,
        bool $bodies,
        ?callable $onResult = null
    ): array {
        $queue = array_values($urls);
        $inFlight = [];
        $results = [];

        $request = function (string $url, int $attempt) use ($client, $bodies, &$inFlight, &$results, $onResult): void {
            try {
                $response = $client->request('GET', $url, ['buffer' => $bodies]);
            } catch (\Throwable $e) {
                // thrown synchronously, e.g. for a malformed URL (missing
                // scheme when the url option is not set) — fail this one
                // URL instead of aborting the whole run; no retry, the
                // error is permanent
                $results[$url] = ['status' => 0, 'error' => $e->getMessage(), 'media' => []];

                if ($onResult !== null) {
                    $onResult($url, $results[$url]);
                }

                return;
            }

            $inFlight[spl_object_id($response)] = [
                'url' => $url,
                'attempt' => $attempt,
                'response' => $response,
            ];
        };

        $finish = function (array $meta, array $result) use (&$inFlight, &$results, $onResult): void {
            unset($inFlight[spl_object_id($meta['response'])]);
            $results[$meta['url']] = $result;

            if ($onResult !== null) {
                $onResult($meta['url'], $result);
            }
        };

        while ($queue !== [] || $inFlight !== []) {
            while (count($inFlight) < $concurrency && $queue !== []) {
                $request(array_shift($queue), 1);
            }

            // stream the window; every completion breaks out to refill it
            try {
                foreach ($client->stream(array_column($inFlight, 'response')) as $response => $chunk) {
                    $meta = $inFlight[spl_object_id($response)];

                    try {
                        if ($chunk->isFirst() === true) {
                            // always consume the status here: after yielding
                            // the first chunk, the stream generator force-
                            // checks unconsumed responses with
                            // getHeaders(true), which throws for every 4xx —
                            // including the error page's 404, which is a
                            // valid result for this crawler
                            $status = $response->getStatusCode();

                            if ($bodies === false) {
                                $response->cancel();
                                $finish($meta, ['status' => $status, 'error' => null, 'media' => []]);
                                break;
                            }

                            continue;
                        }

                        if ($chunk->isLast() === true) {
                            $status = $response->getStatusCode();
                            $type = $response->getHeaders(false)['content-type'][0] ?? '';
                            $content = $bodies ? $response->getContent(false) : '';

                            $finish($meta, [
                                'status' => $status,
                                'error' => null,
                                'media' => $bodies === true && str_contains($type, 'text/html')
                                    ? Urls::media($content)
                                    : [],
                            ]);
                            break;
                        }
                    } catch (\Throwable $e) {
                        unset($inFlight[spl_object_id($response)]);

                        if ($meta['attempt'] === 1) {
                            $request($meta['url'], 2);
                        } else {
                            $results[$meta['url']] = ['status' => 0, 'error' => $e->getMessage(), 'media' => []];

                            if ($onResult !== null) {
                                $onResult($meta['url'], $results[$meta['url']]);
                            }
                        }

                        break;
                    }
                }
            } catch (\Throwable $e) {
                // thrown by the stream generator itself, outside a chunk —
                // the failing response is unknown, so retry or fail the
                // whole window
                $window = $inFlight;
                $inFlight = [];

                foreach ($window as $meta) {
                    if ($meta['attempt'] === 1) {
                        $request($meta['url'], 2);
                    } else {
                        $results[$meta['url']] = ['status' => 0, 'error' => $e->getMessage(), 'media' => []];

                        if ($onResult !== null) {
                            $onResult($meta['url'], $results[$meta['url']]);
                        }
                    }
                }
            }
        }

        return $results;
    }
}
