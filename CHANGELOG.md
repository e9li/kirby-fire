# Changelog

## 0.4.5

Memory hardening for large media libraries on constrained hosts, after a
production run died at thumb 284 of 5296 with a 128 MB CLI limit — and
honest reporting for pages Kirby refuses to cache.

- **The CLI and the Panel present the same state.** Both record per-page
  outcomes (not cacheable, failed with its error message) into one shared
  state file below `site/cache` — keyed by page id and language, so it
  survives domain overrides and multi-domain setups. Opening the Panel
  after a CLI or cron run shows every problem row with its reason
  immediately, no crawling needed; Panel crawl results persist across
  reloads the same way. Cached pages are never stored there — the pages
  cache on disk stays their single source of truth. Local runs also verify
  cache writes against the disk, which catches pages that disable caching
  without a no-store header (`$kirby->response()->cache(false)`).
- **The Panel follows the crawl.** The view auto-scrolls to keep the row
  currently being warmed in sight — on a 3000-row site you no longer lose
  track. Scrolling manually pauses the follow immediately (the view never
  fights the user); a floating flame button jumps back to the action, and a
  back-to-top button appears once the list header leaves the viewport.
- **Warming is incremental now.** `fire:up`, `fire:render` and the Panel's
  fire button skip pages that are provably cached on disk (the same
  per-page check that powers the Panel's green rows) — adding 20 pages
  warms exactly those 20. `--fresh` and the Panel's "Clear cache" remain
  the full-rebuild path; a domain override warms everything, since the
  local cache says nothing about another host.
- **Uncacheable pages are reported instead of celebrated.** A response can
  be HTTP 200 and still warm nothing: when a page's render starts a session
  or sets cookies, Kirby answers `no-store` and skips the cache write.
  `fire:up` and `fire:render` now count and list such pages with a clear
  warning naming the typical causes (`csrf()` or `$kirby->session()` in an
  always-rendered snippet), the summary only counts pages actually cached,
  and the Panel shows them with their own "not cacheable" state instead of
  a green "fire on". Found on a production site where an entire section was
  silently uncacheable while the crawl reported success.

- The commands raise the CLI memory limit to the new `memory` option
  (default `512M`), the way Composer does for itself: image decoding is
  memory hungry (a 24 MP photo needs ~100 MB in GD alone) and memory
  exhaustion is uncatchable — prevention is the only defense. An existing
  higher or unlimited limit is never lowered; `'memory' => false` keeps
  the environment's limit.
- `fire:thumbs` no longer accumulates page models: jobs carry plain
  type/id strings, each model resolves just in time and its memoized
  files/children collections are purged after the thumb — thousands of
  jobs stay flat in memory instead of growing until the limit kills the
  run. Reference cycles are collected periodically.
- New `fire:thumbs --limit N`: render at most N thumbs and report how many
  jobs are left. Finished jobs leave the queue, so repeated limited runs
  converge — for hosts where even the raised limit is not available.
- If the host has the Imagick extension, `'thumbs' => ['driver' =>
  'imagick']` in the site config avoids PHP's memory limit for pixel data
  entirely — worth preferring over GD on shared hosting.

## 0.4.4

- `ignore.page` supports branch rules: `'data/*'` skips `data` and every
  page below it. Plain ids still match exactly — they never covered
  children, which made ignoring hidden data branches (forms, product data)
  effectively impossible one id at a time.

## 0.4.3

Fixes for sites without a configured `url` option, where Kirby's CLI base
URL is `/` and page URLs come in root-relative (found on a production
shared server).

- **Domain rewriting produced double slashes** (`https://domain//path`),
  which many servers answer with 404. The rewrite normalizes the boundary
  now.
- **A malformed target URL aborted the whole crawl** with an uncaught
  "Invalid URL: scheme is missing" exception. Synchronous request errors
  now fail the one URL like any other error.
- `fire:up` refuses to start without a usable base URL and no domain,
  with a clear message — and the prompt no longer offers "leave empty
  for /". Passing a domain remains enough; setting the `url` option is
  recommended (fire:render needs it anyway).
- The Panel handles root-relative page URLs (same unset-url setups).

## 0.4.2

- The CLI shows a progress bar with a rolling window of the last 5 URLs
  instead of one line per URL — a 3000-page crawl is a handful of lines
  now. Failures still print as persistent lines above the window. When the
  output is not a terminal (cron, CI logs, pipes) every item logs as a
  plain `n/total` line as before, and `--quiet` stays fully silent.
- The Panel shows the real cache state when it opens: rows of pages that
  are already cached start as "fire on" instead of everything starting at
  "no fire". Previously the row states were only this browser session's
  crawl progress, which read as "nothing is warmed" right after a
  successful CLI run — the actual cache state was only visible in the
  status line. The check mirrors core's cache id per page and language and
  is verified against entries written by real renders, so a format change
  in Kirby fails the test suite instead of going stale silently.

## 0.4.1

Shared-hosting hardening. A field test on a large real-world site (3376
pages, 4 languages, image-heavy, single-worker dev server,
`max_execution_time` 60) uncovered several failure modes.

- **The Panel crawls from the browser now.** Each page is fetched by the
  Panel user's browser like a normal, anonymous visit — instead of the
  server requesting its own public URL from inside the API request. The old
  self-request design deadlocked single-worker servers and died in
  "Maximum execution time exceeded" fatals on hosts with low execution
  limits, i.e. typical shared hosting. Media URLs are extracted
  client-side; only languages on their own domains still go through the
  server-side route, as a cross-origin fallback.
- The fallback route's HTTP timeout is capped below PHP's
  `max_execution_time`, so a hanging target produces a clean per-row error
  instead of a PHP fatal.
- New `ignore.template` option to skip whole page classes — needed for
  redirect templates: Kirby implements `go()` with `die()`, which no
  crawler can catch, and per-page ignores don't scale to dozens of
  redirecting pages.
- `fire:render` no longer dies silently when a template redirects: a
  shutdown guard names the aborting page and the template to add to
  `ignore.template`. (The die itself is uncatchable — the guard makes it
  diagnosable instead of invisible.)

## 0.4.0

- New `fire:render` command: warms the pages cache **in-process, without any
  HTTP**. `Page::render()` fills the cache itself and the cache keys are
  request-independent, so the entries are exactly what later HTTP requests
  read. Rendering also queues the thumb jobs, so
  `fire:render --fresh && fire:thumbs` is a complete warm-up that needs no
  reachable webserver, no TLS and no loopback — and every generated file
  belongs to one user. Caveat: templates that read request state render with
  an empty CLI request; warm over HTTP in that case.
- Commands exit non-zero when a URL, render or thumb failed, so cron and CI
  can alert on it. The Kirby CLI's global `--quiet` flag silences the
  output; the exit code still carries the outcome.
- New `--fresh` flag on `fire:up` and `fire:render`: flushes the pages cache
  first, so a full re-warm is one command (and wiped media folders get their
  thumb jobs re-queued — the manual flush the README used to prescribe).
- All plugin code moved from global functions into `E9li\Fire\` classes
  (`Commands`, `Jobs`, `Pages`, `PagesCache`, `Renderer`, `Urls`, `Warmer`),
  autoloaded via Kirby's `load()` helper. The global `fire*()` functions no
  longer exist, so they cannot collide with other plugins.
- New renderer tests: in-process renders write real cache entries (single-
  and multilanguage) and template failures are reported per page instead of
  aborting the run.
- CI moved from `.github/workflows/` to `.forgejo/workflows/` — the plugin
  is developed on a Forgejo instance whose runner advertises the `docker`
  label, so `runs-on: ubuntu-latest` never matched and the jobs stayed
  queued forever. Third-party actions are referenced by their full GitHub
  URL now, dependencies install from the lock file, and the passive GitHub
  mirror no longer accumulates stuck workflow runs.

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
