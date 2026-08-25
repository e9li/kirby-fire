<?php

namespace E9li\Fire\Tests;

use E9li\Fire\Warmer;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class EngineTest extends TestCase
{
    private function html(string $body, int $status = 200): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'text/html; charset=utf-8'],
        ]);
    }

    public function testWarmExtractsMediaFromHtml(): void
    {
        $this->app();

        $client = new MockHttpClient($this->html(
            '<img src="https://example.test/media/pages/home/1/img-400x.jpg">'
        ));

        $this->assertSame([
            'status' => 200,
            'error' => null,
            'media' => ['https://example.test/media/pages/home/1/img-400x.jpg'],
        ], Warmer::warm($client, 'https://example.test'));
    }

    public function testWarmReportsHttpErrorsWithoutThrowing(): void
    {
        $this->app();

        $client = new MockHttpClient($this->html('<h1>Not found</h1>', 404));
        $result = Warmer::warm($client, 'https://example.test/missing');

        $this->assertSame(404, $result['status']);
        $this->assertNull($result['error']);
    }

    public function testWarmRetriesTransportErrorsOnce(): void
    {
        $this->app();

        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            if (++$calls === 1) {
                throw new TransportException('connection refused');
            }

            return $this->html('<h1>ok</h1>');
        });

        $result = Warmer::warm($client, 'https://example.test');

        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $calls);
    }

    public function testWarmGivesUpAfterTheRetry(): void
    {
        $this->app();

        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls) {
            $calls++;
            throw new TransportException('connection refused');
        });

        $result = Warmer::warm($client, 'https://example.test');

        $this->assertSame(0, $result['status']);
        $this->assertSame('connection refused', $result['error']);
        $this->assertSame(2, $calls);
    }

    public function testWarmAllProcessesEveryUrlWithBoundedWindow(): void
    {
        $this->app();

        $urls = array_map(fn ($n) => "https://example.test/page-$n", range(1, 7));
        $client = new MockHttpClient(fn () => $this->html('<h1>ok</h1>'));

        $completed = [];
        $results = Warmer::warmAll($client, $urls, 3, true, function (string $url) use (&$completed): void {
            $completed[] = $url;
        });

        $this->assertCount(7, $results);
        $this->assertCount(7, $completed);
        $this->assertSame([200], array_unique(array_column($results, 'status')));
    }

    public function testWarmAllReportsHttpErrorsWithoutThrowing(): void
    {
        // regression: the stream generator force-checks unconsumed responses
        // after their first chunk and threw ClientException for the error
        // page's 404, aborting the whole crawl
        $this->app();

        $client = new MockHttpClient([
            $this->html('<h1>Not found</h1>', 404),
            $this->html('<h1>ok</h1>'),
        ]);

        $results = Warmer::warmAll(
            $client,
            ['https://example.test/error', 'https://example.test'],
            2,
            true
        );

        $this->assertSame(404, $results['https://example.test/error']['status']);
        $this->assertNull($results['https://example.test/error']['error']);
        $this->assertSame(200, $results['https://example.test']['status']);
    }

    public function testWarmAllExtractsMediaInBodyMode(): void
    {
        $this->app();

        $client = new MockHttpClient($this->html(
            '<img srcset="https://example.test/media/a/1/i.jpg 400w, https://example.test/media/a/1/j.jpg 800w">'
        ));

        $results = Warmer::warmAll($client, ['https://example.test'], 5, true);

        $this->assertSame([
            'https://example.test/media/a/1/i.jpg',
            'https://example.test/media/a/1/j.jpg',
        ], $results['https://example.test']['media']);
    }

    public function testWarmAllSkipsBodiesInHeaderOnlyMode(): void
    {
        $this->app();

        $client = new MockHttpClient(fn () => $this->html(
            '<img src="https://example.test/media/a/1/i.jpg">'
        ));

        $results = Warmer::warmAll($client, ['https://example.test/media/x.jpg'], 5, false);

        $this->assertSame(
            ['status' => 200, 'error' => null, 'media' => []],
            $results['https://example.test/media/x.jpg']
        );
    }

    public function testWarmAllFailsMalformedUrlsWithoutAborting(): void
    {
        // request() throws synchronously for URLs without a scheme (the
        // unset-url-option case) — that must fail the one URL, not kill
        // the whole crawl. MockHttpClient does not validate URLs like the
        // real clients do, so the factory replicates the synchronous throw.
        $this->app();

        $client = new MockHttpClient(function (string $method, string $url) {
            if (str_contains($url, 'example.test') === false) {
                throw new \InvalidArgumentException('Invalid URL: scheme is missing in "/relative".');
            }

            return $this->html('<h1>ok</h1>');
        });

        $results = Warmer::warmAll($client, ['/relative', 'https://example.test'], 2, true);

        $this->assertSame(0, $results['/relative']['status']);
        $this->assertStringContainsString('scheme', $results['/relative']['error']);
        $this->assertSame(200, $results['https://example.test']['status']);
    }

    public function testCappedTimeoutLeavesExecutionHeadroom(): void
    {
        // no execution limit (CLI): the configured timeout wins
        $this->assertSame(60.0, Warmer::cappedTimeout(60.0, 0, 120.0));

        // 60s limit, 10s spent → 45s budget (5s headroom to answer cleanly)
        $this->assertSame(45.0, Warmer::cappedTimeout(60.0, 60, 10.0));

        // configured timeout below the budget stays untouched
        $this->assertSame(15.0, Warmer::cappedTimeout(15.0, 60, 10.0));

        // nearly exhausted request: never below the 3s floor
        $this->assertSame(3.0, Warmer::cappedTimeout(60.0, 30, 29.0));
    }

    public function testWarmAllRetriesAndReportsTransportErrors(): void
    {
        $this->app();

        $calls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$calls) {
            $calls[$url] = ($calls[$url] ?? 0) + 1;

            if ($url === 'https://example.test/flaky' && $calls[$url] === 1) {
                return new MockResponse('', ['error' => 'connection reset']);
            }

            if ($url === 'https://example.test/dead') {
                return new MockResponse('', ['error' => 'connection refused']);
            }

            return $this->html('<h1>ok</h1>');
        });

        $results = Warmer::warmAll(
            $client,
            ['https://example.test/flaky', 'https://example.test/dead', 'https://example.test/fine'],
            2,
            true
        );

        $this->assertSame(200, $results['https://example.test/flaky']['status']);
        $this->assertSame(2, $calls['https://example.test/flaky']);

        $this->assertSame(0, $results['https://example.test/dead']['status']);
        $this->assertSame(2, $calls['https://example.test/dead']);

        $this->assertSame(200, $results['https://example.test/fine']['status']);
    }
}
