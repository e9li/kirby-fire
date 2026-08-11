# Fire up the Kirby cache!

## Issues & Feedback

This library is developed at [git.e9li.com](https://git.e9li.com/e9li/kirby-fire) and mirrored to [GitHub](hhttps://github.com/e9li/kirby-fire).
If you found a bug or have a suggestion, you can either:

- Open an issue on [GitHub](https://github.com/e9li/kirby-fire/issues)
- Send an email to rafael@e9li.com

## Installation

- `composer require e9li/kirby-fire`

## Commands

```bash
kirby fire:up                 # crawl every page in every language, then its thumbs
kirby fire:up --no-media      # …without the thumbs
kirby fire:thumbs             # render pending thumbs in-process
```

`fire:up` warms the page cache by requesting every page over HTTP, and picks the
thumbs out of the returned `src`/`srcset` attributes so a crawl warms those too.

Kirby does not generate a thumbnail while a page renders — it writes one job file
per thumb and runs the darkroom only when the media URL is first requested. So
`fire:up`'s thumb pass costs a full HTTP request and response body per size per
image. `fire:thumbs` works the pending jobs off directly instead, which is what
makes the two-step form worth using on a large site:

```bash
kirby fire:up --no-media && kirby fire:thumbs
```

Two things to know about `fire:thumbs`:

- It can only render what a page render has already queued. With the page cache
  warm nothing re-renders, so **wiping the media folder means clearing the page
  cache first** — otherwise the thumbs can never be regenerated.
- `fire:up` runs over HTTP, so its job files belong to the webserver user while
  `fire:thumbs` runs as the CLI user. Both need write access to the media folder.

## Panel

The plugin adds a **Fire** view that does the same crawl from the browser, plus a
button to flush the page cache.

## Options

```php
// site/config/config.php
'e9li.kirby-fire' => [
    // warm a different domain than kirby()->url()
    'domain' => '',
    'ignore' => [
        'page'     => ['error'],
        'language' => [],
    ],
],
```
