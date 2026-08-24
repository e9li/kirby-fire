# Changelog

## 0.2.1

Bugfix release.

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
  shows a "Cache is on 🔥" notification.
- **PHP 8.1 was declared but never worked.** The shipped Symfony packages
  require PHP >= 8.2, so the constraint now says so. The composer platform is
  pinned to 8.2 so dependency resolution always targets the minimum supported
  version.
- Dependencies updated (Symfony 7.0.3 → 7.4.17), which resolves the low
  severity advisories CVE-2026-45071 (dom-crawler) and CVE-2024-50342
  (http-client) in the shipped vendor folder.
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
