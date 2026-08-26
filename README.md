# Fire up the Kirby cache!

## Issues & Feedback

This library is developed at [git.e9li.com](https://git.e9li.com/e9li/kirby-fire) and mirrored to [GitHub](https://github.com/e9li/kirby-fire).
If you found a bug or have a suggestion, you can either:

- Open an issue on [GitHub](https://github.com/e9li/kirby-fire/issues)
- Send an email to rafael@e9li.com

## Installation

- `composer require e9li/kirby-fire`

## Requirements

- The **pages cache must be active**, otherwise every warmed page is rendered
  and thrown away — `fire:up` warns when it is off:

  ```php
  // site/config/config.php
  'cache' => [
      'pages' => ['active' => true],
  ],
  ```

- The commands need the [Kirby CLI](https://github.com/getkirby/cli)
  (`composer global require getkirby/cli`).

There is no webserver requirement: the Panel crawls **from your browser**
(every page is fetched like a normal visit), the CLI requests the site from
outside, and `fire:render` warms without HTTP entirely — all three work on
shared hosting, behind proxies, and on single-worker dev servers.

## Commands

```bash
kirby fire:up                     # crawl every page in every language, then its thumbs
kirby fire:up --no-media          # …without the thumbs
kirby fire:up --concurrency 10    # crawl with 10 requests in flight (default 5)
kirby fire:up --insecure          # skip TLS verification (local dev certificates)
kirby fire:up --fresh             # flush the pages cache first
kirby fire:render                 # render pages in-process into the cache — no HTTP
kirby fire:thumbs                 # render pending thumbs in-process
```

Every command exits non-zero when something failed, and the Kirby CLI's
global `--quiet` flag silences the output — together that makes them
cron-friendly: `kirby fire:render --fresh --quiet && kirby fire:thumbs --quiet`.

**Warming is incremental**: pages that are already cached are skipped, so
after an editor adds 20 pages, `fire:up` (or the Panel's fire button) warms
exactly those 20. **`--fresh` is the full re-cache**: it flushes the pages
cache first, so everything regenerates. There is deliberately no separate
`--force` — requesting a cached page returns the cached copy without
re-rendering, so a real re-cache always starts with a flush. In the Panel,
"Clear cache" then the fire button is the same full rebuild. One exception:
with a domain override the local cache says nothing about the target host,
so everything is warmed.

`fire:up` warms the page cache by requesting every page over HTTP — with up to
`--concurrency` requests in flight — and picks the thumbs out of the returned
`src`/`srcset` attributes so a crawl warms those too. Thumb requests are
dropped as soon as the status line arrives: Kirby generates the thumbnail
before the first body byte, so the images themselves are never downloaded.

The error page is warmed like any other page — an image-rich 404 page profits
just as much. It answers with HTTP 404 by design, so for this one page that
status counts as warmed instead of failed.

A page can answer 200 and still not be cached: when its render starts a
session or sets cookies, Kirby responds with `no-store` and skips the cache
write. The commands report such pages ("not cacheable") instead of counting
them as warmed — typical causes are `csrf()` or `$kirby->session()` in an
always-rendered snippet (header, footer, popup). Fix the template, not the
crawler: read cookies lazily, or fetch tokens and cart states via JS.

The CLI and the Panel share their results: per-page outcomes (not
cacheable, failed and why) are recorded in one state file below
`site/cache`, so the Panel presents a CLI or cron run's outcome on load —
green rows from the pages cache itself, problem rows with their reasons
from the last run, wherever it ran.

Kirby does not generate a thumbnail while a page renders — it writes one job file
per thumb and runs the darkroom only when the media URL is first requested. So
`fire:up`'s thumb pass still costs one HTTP round-trip per size per image, even
though no bodies are transferred. `fire:thumbs` works the pending jobs off
directly in-process instead, which is what makes the two-step form worth using
on a large site:

```bash
kirby fire:up --no-media && kirby fire:thumbs
```

## In-process warming

`fire:render` skips HTTP entirely: it renders every page in every language
in-process. `Page::render()` fills the pages cache itself, and the cache keys
are request-independent, so the entries are exactly what later HTTP requests
read. Rendering also queues the thumb job files — the complete warm-up for a
large site is:

```bash
kirby fire:render --fresh && kirby fire:thumbs
```

Compared to the HTTP crawl this needs no reachable webserver (including
hosting setups where the server cannot request its own public URL), no TLS,
and every generated file belongs to the CLI user. Two caveats:

- Set the `url` option — the URLs inside the cached HTML are based on it.
- Templates that read request state (query, headers, session, or
  `$_SERVER['DOCUMENT_ROOT']`) render with an empty CLI request. If your
  site depends on that, warm over HTTP instead.
- Templates that redirect via `go()` end the process — Kirby implements
  `go()` with `die()`, which nothing can catch. `fire:render` reports the
  page and its template when that happens; add the template to the
  `ignore.template` option and run again.

## Thumbs

Three things to know about `fire:thumbs`:

- Image decoding needs real memory (a 24 MP photo is ~100 MB in GD), so the
  commands raise the CLI memory limit to the `memory` option (512M default).
  On hosts where that is blocked, `fire:thumbs --limit 500` renders in
  batches — finished jobs leave the queue, so repeated runs converge. If the
  Imagick extension is available, `'thumbs' => ['driver' => 'imagick']`
  avoids PHP's memory limit for pixel data entirely.

- It can only render what a page render has already queued. With the page cache
  warm nothing re-renders, so **wiping the media folder means clearing the page
  cache first** — otherwise the thumbs can never be regenerated.
- `fire:up` runs over HTTP, so its job files belong to the webserver user while
  `fire:thumbs` runs as the CLI user. Both need write access to the media folder.

## Panel

The plugin adds a **Fire** view with a button to flush the page cache. Pages
that are already cached show as "fire on" when the view opens — the list
reflects the server-side cache, however it was warmed. Its crawl runs **in
your browser**: each page is fetched anonymously like a
normal visit (so responses stay cacheable), and the thumbs found in the HTML
are fetched the same way. Nothing is proxied through the server, so the
crawl works wherever the site itself works — shared hosting included. Only
languages living on their own domains fall back to a server-side request.

## Development

```bash
composer update            # installs the dev dependencies (PHPUnit, Kirby)
composer test              # runs the test suite
composer install --no-dev  # restores the lean vendor folder before committing
npm run build              # rebuilds the Panel assets (index.js / index.css)
```

The tests boot Kirby from the plugin's vendor folder, or from a surrounding
Kirby installation when the plugin lives in `site/plugins/`. The committed
`vendor/` folder must stay dependency-only — run `composer install --no-dev`
after testing, before you commit.

## Options

```php
// site/config/config.php
'e9li.kirby-fire' => [
    // warm a different domain than kirby()->url()
    'domain' => '',
    // requests in flight during a crawl
    'concurrency' => 5,
    // per-request timeout in seconds
    'timeout' => 60,
    // TLS certificates are verified by default; set to true for
    // local dev certificates (or pass --insecure to fire:up)
    'insecure' => false,
    // CLI memory limit for the commands — image decoding is memory
    // hungry; never lowers the environment's limit, false disables
    'memory' => '512M',
    'ignore' => [
        // page ids to skip; append /* to ignore a whole branch:
        // 'data/*' skips data and every page below it
        'page'     => [],
        // template names to skip — whole page classes, e.g. redirect
        // templates or hidden data pages
        'template' => [],
        // language codes to skip
        'language' => [],
    ],
],
```
