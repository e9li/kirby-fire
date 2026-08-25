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
- The warmer requests the site over HTTP, and the Panel does so **from within
  a request** — the server must be able to handle concurrent requests. With
  PHP's built-in server this needs worker processes, or the self-request
  deadlocks until it times out:

  ```bash
  PHP_CLI_SERVER_WORKERS=8 php -S localhost:8000 -t public kirby/router.php
  ```

  `fire:render` warms without HTTP and has neither requirement.

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

`fire:up` warms the page cache by requesting every page over HTTP — with up to
`--concurrency` requests in flight — and picks the thumbs out of the returned
`src`/`srcset` attributes so a crawl warms those too. Thumb requests are
dropped as soon as the status line arrives: Kirby generates the thumbnail
before the first body byte, so the images themselves are never downloaded.

The error page is warmed like any other page — an image-rich 404 page profits
just as much. It answers with HTTP 404 by design, so for this one page that
status counts as warmed instead of failed.

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
- Templates that read request state (query, headers, session) render with an
  empty CLI request. If your site depends on that, warm over HTTP instead.

## Thumbs

Two things to know about `fire:thumbs`:

- It can only render what a page render has already queued. With the page cache
  warm nothing re-renders, so **wiping the media folder means clearing the page
  cache first** — otherwise the thumbs can never be regenerated.
- `fire:up` runs over HTTP, so its job files belong to the webserver user while
  `fire:thumbs` runs as the CLI user. Both need write access to the media folder.

## Panel

The plugin adds a **Fire** view that does the same crawl from the browser, plus a
button to flush the page cache.

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

### Releasing

`vendor/` is committed on purpose: ZIP downloads and Git submodule installs
run no Composer step, so the plugin must work as a plain folder. Composer
installs resolve the same dependencies into the site's own vendor folder and
simply ignore the embedded copy. The flip side: embedded dependencies only
update when a new version is tagged — release a patch when `composer audit`
reports an advisory.

1. `composer update && composer test && composer install --no-dev`
2. `npm run build` if Panel sources changed
3. Bump `version` in `composer.json`, update `CHANGELOG.md`
4. Commit, tag `vX.Y.Z`, push — Packagist follows the GitHub mirror
   automatically

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
    'ignore' => [
        // page ids and language codes to skip
        'page'     => [],
        'language' => [],
    ],
],
```
