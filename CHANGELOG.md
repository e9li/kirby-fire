# Changelog

## 0.3.0

HTTP engine rework — the crawl is built for sites with many pages and images.

- Concurrent crawling: `fire:up` keeps up to `--concurrency` requests in
  flight (default 5, also available as an option). The dev site's cold crawl
  dropped from a few seconds to ~0.15 s; real sites can expect a speedup
  roughly matching the concurrency level.
- Thumbs are warmed without downloading them: media requests are cancelled
  as soon as the status line arrives — Kirby generates the thumbnail before
  the first body byte, so image bodies never cross the wire. On image-heavy
  sites this cuts crawl traffic from potentially gigabytes to kilobytes.
- Transport errors are retried once before a URL is reported as failed.
- TLS certificates are verified now (behavior change — verification used to
  be off). For local dev certificates set the `insecure` option or pass
  `--insecure`.
- New options: `concurrency` (5), `timeout` (60 seconds), `insecure`
  (false).
- `symfony/browser-kit` is no longer a dependency: the plugin talks to
  Symfony HttpClient directly. Dropping it (and its dom-crawler/html5
  parser) shrinks the shipped vendor folder, and HTML responses are no
  longer parsed into a DOM nothing ever used.
- Engine unit tests with Symfony's MockHttpClient: retry behavior, the
  bounded request window, header-only mode, and HTTP error responses
  surviving the stream generator's forced status check (a 404 from the
  error page aborted the whole crawl in an early version of the engine).

## 0.2.3

- Panel translations in en, de, de-ch, fr, it, ru and sr — every Panel
  string goes through Kirby's translation system now, including the menu
  label, buttons, states, the empty screen and the completion notification.
- New cache status line in the Panel: shows whether the pages cache is
  active and how many pages are currently cached (file cache driver). When
  the pages cache is off, a warning box appears instead — previously only
  the CLI warned about that, while the Panel happily showed green rows that
  cached nothing. The row states are client-side only; this line shows the
  actual server-side cache state (which normal visitor traffic fills too).
- CI workflow (`.github/workflows/ci.yml`, GitHub Actions / Gitea Actions
  compatible): `composer validate`, the PHPUnit suite on PHP 8.2–8.5 and a
  weekly `composer audit` of the locked dependencies.

## 0.2.2

Bugfix release. (0.2.1 was never tagged; its changes shipped here.)

- **Single-language sites warmed nothing.** `fire:up` and the panel iterated
  `kirby()->languages()`, which is empty without a `site/languages` folder, so
  every page was skipped. Both now fall back to a single language-less pass.
- **The home page was skipped or rejected.** Its URL is the site URL without a
  trailing slash, which the allowed-URL guard and the domain rewrite did not
  match. Both accept the exact base URL now (the slash-suffixed prefix check
  for all other URLs stays, so `https://example.com.evil.com` is still
  blocked).
- **Languages on their own domains were blocked.** Each language's base URL is
  now part of the allowed bases. URLs that cannot be mapped onto an explicit
  `fire:up` target domain are counted and reported instead of silently
  skipped.
- **The `domain` argument was ignored once the config option was set.**
  Precedence is now CLI argument → config option → prompt, and the prompt only
  appears on a terminal, so cron jobs fall through to the site URL instead of
  hanging.
- **The last crawled row in the panel could stay stuck on "fire up".** Vue 2
  cannot observe by-index array assignments; the view uses `splice()` now.
- **The "Stop" button stayed after the crawl finished.** The crawl never
  flipped its running state back when it ran past the last row, so the
  header kept offering "Stop" although nothing was running (and "Continue"
  could wedge it again). Completion now returns to the idle buttons and
  shows a "Cache is on!" notification with the plugin's flame icon.
- All emojis removed from the CLI output and the Panel — iconography comes
  from the plugin's registered SVG icons instead.
- **PHP 8.1 was declared but never worked.** The shipped Symfony packages
  require PHP >= 8.2, so the constraint now says so. The composer platform is
  pinned to 8.2 so dependency resolution always targets the minimum supported
  version.
- Dependencies updated (Symfony 7.0.3 → 7.4.17), which resolves the low
  severity advisories CVE-2026-45071 (dom-crawler) and CVE-2024-50342
  (http-client) in the shipped vendor folder.
- Symfony 8 is allowed (`^7.0 || ^8.0`) and PHP 8.5 added to the supported
  versions: Composer installs on PHP 8.4+ resolve Symfony 8.x, while the
  shipped vendor folder stays on 7.4 LTS — Symfony 8 requires PHP >= 8.4,
  and the embedded copy has to run on every supported PHP version (8.2+).
- New: `fire:up` warns when the pages cache is not active — previously it
  reported success while Kirby silently cached nothing.
- New: the error page no longer shows up as a failure. It answers with
  HTTP 404 by design, so every run reported it as failed — confusing noise.
  It is still crawled (an image-rich error page profits from warming like
  any other page, including its thumbs); the 404 status simply counts as
  warmed for this one page.
- README: new requirements section — pages cache, Kirby CLI, and concurrent
  request handling (the Panel crawl requests the site from within a request;
  single-worker dev servers deadlock without `PHP_CLI_SERVER_WORKERS`).
- Dev: PHPUnit test suite (`composer test`) covering the URL guards, page
  listing (single- and multilang, per-language domains, ignores, error page
  flag), media URL extraction and thumb job parsing.
