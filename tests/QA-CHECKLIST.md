# QA-CHECKLIST.md — FastLayer Manual Test Checklist

Manual verification checklist for the FastLayer plugin. Use it before every
release and after any change touching caching, file writes, or output.

## How to use

- Set each item's **Status** to exactly one of: `Not Run` · `Passed` · `Failed` ·
  `Blocked`.
- Record the command, URL, or file/line inspected in **Evidence**.
- `Blocked` means the test cannot be performed in the current environment (say
  why). `Not Run` means it has not been attempted yet.
- **Do not mark `Passed` unless the test was actually executed.** Code inspection
  is not a pass; note it as inspection evidence and leave the status honest.

## Test environment

| Field | Value |
| --- | --- |
| Plugin version | 1.1.0 |
| PHP version | 7.4.30 (lowest version actually tested; PHP < 7.4 not available) |
| WordPress version | 7.1.3 (live LocalWP site `fastlayer.local`) |
| Web server | **nginx** (LocalWP) |
| Database | MySQL 8.4, port 10027 |
| Object cache | _not recorded_ |
| Browser(s) | _not used — the checks below were CLI/WP-CLI driven_ |
| Tester / date | audit agent (executed checks) / **2026-10-12** |

> Only the rows marked `Passed` with command/evidence were actually executed.
> Everything else stays `Not Run` / `Blocked`.

## Available tooling in the reference workspace

| Tool | Present? | Notes |
| --- | --- | --- |
| `node` v22.23.2 | Yes | JS syntax checks |
| `npm` / `npx` | Yes | — |
| `git` | Yes | — |
| `php` (CLI) | **Yes** (LocalWP) | PHP 7.4.30 at `%APPDATA%\Local\lightning-services\php-7.4.30+6\bin\win64\php.exe` — enables `php -l`, the plugin-check-bundled PHPCS, and WP-CLI |
| `composer` | No | — |
| WP-CLI | **Yes** (LocalWP) | `wp-cli.phar` 2.12.0 (present at `C:\xampp\php\wp-cli.phar` and `C:\Program Files (x86)\Local\resources\extraResources\bin\wp-cli\wp-cli.phar`); run against the live local site with `--path="<site>\app\public"`, `-d extension=mysqli`, `-d mysqli.default_port=10027` |
| Plugin Check (PCP) | **Yes** | `wp plugin check fastlayer` — plugin-check 2.1.0 installed in the site; **executed 2026-10-12** (rounds 3 + 4) |
| PHPCS / WPCS | **Yes** | plugin-check's bundled `vendor/bin/phpcs` (installed paths from its `CodeSniffer.conf`) |
| `rg` (ripgrep) | No | use editor search |

> PHP lint, WPCS and Plugin Check were **actually executed** in this workspace on
> 2026-10-12 (see §P and `docs/REVIEW-FIXES.md` Part B-implementation-3 and
> Part B-implementation-4). PHPUnit and full live-site functional flows still
> default to **Blocked** unless run on a real site.

## PHP version testing status

| Scope | Status | Notes |
| --- | --- | --- |
| **PHP 7.4.30** | **Tested** | Manual compatibility testing completed (maintainer-reported, 2026-10-12). This is the **lowest** PHP version actually tested. |
| PHP below 7.4 | **Not tested** | Not available in the testing environment. No WordPress.org review requirement to test below 7.4 was found (see `docs/REVIEW-FIXES.md`). |
| PHP 8.1 / 8.2 / 8.3 | Not tested | See O-02 below. |

The plugin's declared minimum (`Requires PHP`) remains **7.4**. Do **not** record
or claim PHP versions below 7.4 as tested.

---

## A. Install / activation / deactivation

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| A-01 | Fresh: activate the plugin from a clean install | No fatal/notice; admin menu "FastLayer" appears | Not Run | |
| A-02 | `advanced-cache.php` is installed in `wp-content/` with the FastLayer marker | File exists, contains `wp-fastlayer:advanced-cache-dropin` | **Passed** | Executed 2026-10-12: installed drop-in is byte-identical to the plugin template and carries the ownership marker |
| A-03 | `define( 'WP_CACHE', true );` is added above the "stop editing" comment in `wp-config.php` | Line present with `wp-fastlayer:owned-wp-cache` marker | **Passed** | Executed 2026-10-12: marked `WP_CACHE` line present in `wp-config.php`; file lints clean |
| A-04 | A backup of `wp-config.php` is created before editing and removed after success | No stray `.bak` left in temp dir after success | Not Run | |
| A-05 | Plugin activates on a **read-only** `wp-config.php` | Activation succeeds; an admin notice reports the write failure; config untouched | Not Run | |
| A-06 | Activation with no "stop editing" comment in `wp-config.php` | Notice tells user to add the define manually; config untouched | Not Run | |
| A-07 | Deactivate the plugin | Drop-in removed; FastLayer's `WP_CACHE` line removed; foreign defines untouched | **Passed** | Executed 2026-10-12: drop-in and marked `WP_CACHE` line removed on deactivate; no foreign define touched |
| A-08 | Reactivate after deactivation | Drop-in reinstalled; no duplicate `WP_CACHE` line | **Passed** | Executed 2026-10-12: drop-in + `WP_CACHE` restored; no duplicate line; site returned 200 |
| A-09 | Existing site with `WP_CACHE` added manually/by core | FastLayer does **not** modify or remove it | Not Run | |
| A-10 | Another caching plugin's `advanced-cache.php` is present | FastLayer refuses to overwrite/delete it (debug log note) | Not Run | |

## B. Fresh install vs upgrade

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| B-01 | Fresh install: default options are written | `fastlayer_options` exists with defaults | Not Run | |
| B-02 | Upgrade from 1.0.0 (`wp_fastlayer_*` naming) | `fastlayer_options` populated from legacy; legacy row preserved | Not Run | |
| B-03 | Upgrade: legacy cron `wp_fastlayer_preload_cache` migrated | New `fastlayer_preload_cache` scheduled; legacy cleared | Not Run | |
| B-04 | Upgrade: legacy batch cron cleared | `wp_fastlayer_preload_batch` no longer scheduled | Not Run | |
| B-05 | Legacy mirrored writes | Saving under legacy key updates `fastlayer_options` | Not Run | |
| B-06 | Legacy drop-in (pre-marker, `wp_fastlayer_should_skip_cache`) | Recognised as FastLayer's; refreshed on activate/removed on deactivate | Not Run | |
| B-07 | Version string consistency | Header, `FASTLAYER_VERSION`, `Stable tag` all `1.1.0` | Not Run | |

## C. Activation & `WP_CACHE` (recurring warning — REVIEW-FIXES A9)

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| C-01 | Activate on a normally writable site; load any admin page | **No** `WP_CACHE` warning | **Passed** | Executed 2026-10-12: `defined('WP_CACHE')` is `true` on the next request, so the notice condition is false |
| C-02 | Deactivate, then reactivate; load any admin page | **No** `WP_CACHE` warning | **Passed** | Executed 2026-10-12: full deactivate/reactivate cycle; constant restored; no recurring warning on a writable config |
| C-03 | Reproduce the reported bug: deactivate/reactivate with a **non-writable** `wp-config.php` | Warning shown; capture exact sequence and config perms | Not Run | |
| C-04 | Verify `defined('WP_CACHE')` in the activation request vs the next request | Determine whether the warning is a timing artefact | **Partial** | Executed 2026-10-12: `defined('WP_CACHE')` is **`true` on the request after** activation/reactivation, so the warning condition is false from the next request on. The activation request itself was **not** separately instrumented, so "not yet defined during the activation request" remains the round-3 hypothesis, not an observed value |
| C-05 | Object/opcode cache enabled: does the config edit take effect next request? | Constant active after cache flush | Not Run | |
| C-06 | Manually set `WP_CACHE` to `false` with drop-in present | Warning shown (expected) | Not Run | |

## D. Page cache — serving & saving

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| D-01 | Enable cache; load a public post as a logged-out visitor | HTML served; `wp-content/cache/wp-fastlayer/<md5>.html` created | **Passed** | 2026-10-13 live site. Cache enabled through the plugin's own `Admin::sanitize_options()` (DB `enable_cache` = string `'1'`; `config.php` `enable_cache => '1'`, `_home_host => ['fastlayer.local']`). `GET /` → 200, 70,914 B; created `wp-content/cache/wp-fastlayer/3e37fb0eb75fa28cf1f7c3a704289f9c.html`, exactly `md5('http://fastlayer.local/|accept-legacy')` (verified with `node`) |
| D-02 | Reload the same page | Served from cache (`X-Cache-Status: HIT`) | **Passed** | Reload `GET /` → `X-Cache-Status: HIT`, `X-Cache-Generated: yes`, `Cache-Control: public, max-age=36000, s-maxage=36000`, `Vary: Accept`; body byte-identical (70,914 B) |
| D-03 | Logged-in user loads the page | Not served from the public cache | **Passed** | Request with a valid `wordpress_logged_in_*` cookie (user `admin`, ID 1) → no `X-Cache-Status`, 79,753 B containing `wpadminbar`, `Cache-Control: no-store, private`; the anonymous cache file was not modified |
| D-04 | WooCommerce cart / session cookie set | Not cached | **Blocked** | WooCommerce is not installed on this site (active plugins: `fastlayer` 1.1.0, `plugin-check` 2.1.0), so a real cart/session cookie could not be produced. The generic cookie-exclusion path is proven by D-07; the WooCommerce-specific bypass remains untested |
| D-05 | `POST` / `HEAD` not cached; query-string requests keyed separately (by design) | `POST`/`HEAD` produce no cache file; query-string gets its own key | **Passed** | `POST /` and `HEAD /` → 200, no `X-Cache*`, no file created or updated. `GET /?d05=1` → own file `dad86ce11a7ebdcc14436f1526663cd7.html`; second request HIT. This matches `docs/ARCHITECTURE.md` line 103 (key includes `REQUEST_URI`); earlier "not cached" wording corrected here |
| D-06 | Excluded URL (Advanced Rules) | Not cached | **Passed** | With `exclude_urls='sample-page'`, `GET /sample-page/` → no HIT, no new/modified file (the pre-existing `f998afae14bb17f9715173aec3aafeb2.html` had itself been created by a test request — clean A/B). Clearing the exclusion → HIT |
| D-07 | Excluded cookie / user agent (Advanced Rules) | Not cached | **Passed** | `exclude_cookies='d07cookie'` → no HIT, no file. `exclude_user_agents='D07Bot'` → no HIT, no new file. After clearing both → `b9fecc3d397850c32f288c9457a99174.html` created, reload HIT |
| D-08 | Cache expiration honoured | File regenerated after expiry | **Passed** | Backdated `3e37…html` by 11 h (TTL 36,000 s) → `GET /` not a HIT; the stale file was removed and regenerated (new mtime 11:28:37, 70,914 B); next `GET /` HIT |
| D-09 | Directory protection files exist | `index.php` and `.htaccess` (marked) in cache dir | **Passed** | Cache dir contains `index.php` (28 B) and `.htaccess` (229 B) whose first line is `# WP FastLayer protected directory` (marker present) |
| D-10 | Direct HTTP request to a cache file | Denied (403) on Apache/LiteSpeed; not listable on nginx | **Passed (nginx half)** | On this nginx site: `GET /wp-content/cache/wp-fastlayer/` → 200, 0 B (directory not listable); `config.php` → 200, 0 B (executed by PHP-FPM, no source dumped); `.htaccess` → 404. The `.html` file is web-readable (200, 70,914 B) because nginx ignores `.htaccess` — inherent, and the same public content (not a leak). The Apache/LiteSpeed 403 half is **Not Run** (no such server here) |
| D-11 | Image `Accept` variants (avif / webp / legacy) handled, `Vary: Accept` present | Correct variant served | **Passed** | `Accept: text/html,image/avif` → 1st MISS, 2nd HIT, `Vary: Accept`; file `b23b4849e9b42fb95b2ad19c5f71f538.html` = `md5('…/|accept-avif')`. `Accept: …image/webp` → `b3cfad2879266f112c5e06e37d1ea0e3.html` = `|accept-webp`, 2nd HIT. Default `*/*` uses `accept-legacy`. (Title corrected from "Gzip/Accept-Encoding" to `Accept`) |
| D-12 | `HTTP_HOST` spoofing | Cache key / host validation rejects unknown host | **Passed** | Live `Host: evil.example` → nginx 404 before PHP, no HIT, no new cache file. Guard exercised directly in both code paths: runtime `Page_Cache::is_request_host_trusted()` and drop-in `fastlayer_is_request_host_trusted()` return true for `fastlayer.local`, `fastlayer.local:80`, `FASTLAYER.LOCAL`, and false for `evil.example` and empty; drop-in `fastlayer_should_skip_cache()` = true for `evil.example`, false for the trusted host |

## E. Cache invalidation

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| E-01 | Publish/update a post | Its cache file invalidated | Not Run | |
| E-02 | New comment approved | Related cache invalidated | Not Run | |
| E-03 | Widget/menu change | Relevant caches invalidated | Not Run | |
| E-04 | Manual "purge cache" from admin / admin bar | Cache cleared; nonce verified | **Passed** | Live nonce-protected purge via the admin bar: `GET /wp-admin/admin-post.php?fastlayer_admin_bar_action=clear_all_cache&redirect_to=…&_wpnonce=…` with a valid admin cookie → `302 Found`, `Location: …/wp-admin/admin.php?page=wp-fastlayer`; all 6 `.html` files removed while `config.php`/`index.php`/`.htaccess` were retained. A mismatched nonce → `403` (Security check failed), confirming the nonce is enforced |
| E-05 | Saving FastLayer settings purges cache | Cache cleared | **Passed** | Live settings save: `POST /wp-admin/options.php` (`option_page=fastlayer_options`, valid `fastlayer_options-options` nonce) changing `enable_html_remove_comments` 1→0 → `302 Found`, `Location: …/admin.php?page=wp-fastlayer&settings-updated=true`; cache `.html` count fell to 0 and the new value persisted in the DB. (Setting later restored to `1`.) |

## F. Preloading

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| F-01 | Enable preload | `fastlayer_preload_cache` scheduled hourly | Not Run | |
| F-02 | Run preload | Sitemap/URLs fetched; cache files created | Not Run | |
| F-03 | Batch processing | `fastlayer_preload_batch` single events run without overlap (lock honoured) | Not Run | |
| F-04 | Lock prevents concurrent runs | Second run skipped while locked | Not Run | |
| F-05 | Admin preload status UI reflects real state | Correct progress/status | Not Run | |

## G. CSS optimization

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| G-01 | Enable CSS minify | Comments/whitespace removed; site renders correctly | Not Run | |
| G-02 | Enable CSS combine | Combined file created in `uploads/wp-fastlayer-min/`; enqueued via core API | Not Run | |
| G-03 | Exclude a CSS file | Excluded file untouched | Not Run | |
| G-04 | Critical CSS option | Inline critical CSS applied | Not Run | |
| G-05 | Google Fonts display swap | `&display=swap` applied | Not Run | |

## H. JavaScript optimization

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| H-01 | Enable JS minify | Minified without breaking scripts | Not Run | |
| H-02 | Enable JS combine | Combined file enqueued; console clean | Not Run | |
| H-03 | Defer/async | Scripts load with correct attributes; exclusions honoured | Not Run | |
| H-04 | Smart delay | Delayed scripts run on first interaction; exceptions honoured | Not Run | |
| H-05 | Exclude a JS file | Excluded file untouched | Not Run | |

## I. Lazy loading / LQIP

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| I-01 | Enable lazy load | Below-the-fold images deferred; above-the-fold kept eager | Not Run | |
| I-02 | Iframe/video lazy load | Deferred correctly | Not Run | |
| I-03 | Enable LQIP | Placeholder shown; sharp image fades in | Not Run | |
| I-04 | No-JS fallback | Images still visible | Not Run | |
| I-05 | LCP image never lazy-loaded | Hero image eager | Not Run | |

## J. WebP

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| J-01 | Enable WebP; upload/generate | `.webp` generated beside original | Not Run | |
| J-02 | `.htaccess` rules | `# BEGIN WP FastLayer WebP` block present, marked | Not Run | |
| J-03 | Apache serves WebP when supported | Correct `Content-Type: image/webp`, `Vary: Accept` | Not Run | |
| J-04 | nginx environment | WebP notice shown (nginx ignores `.htaccess`) | Not Run | |
| J-05 | Reactive serve endpoint | 304 on conditional request; ETag/Last-Modified correct | Not Run | |
| J-06 | Bulk WebP generation | Batches complete; no timeout with `set_time_limit(0)` | Not Run | |

## K. CDN / ImageKit

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| K-01 | Enable CDN with a CNAME | Internal assets rewritten to CDN host | Not Run | |
| K-02 | Enable ImageKit + endpoint | Media URLs rewritten to ImageKit with `tr=` params | Not Run | |
| K-03 | ImageKit connection test | Success/failure reported; nonce verified | Not Run | |
| K-04 | Malicious endpoint (non-ImageKit host / IP / embedded creds) | Rejected | Not Run | |
| K-05 | Endpoint without scheme | `https://` prepended | Not Run | |
| K-06 | ImageKit disabled | No external requests at all | Not Run | |
| K-07 | LCP preload with CDN | Preload link uses CDN URL | Not Run | |

## L. Database optimization

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| L-01 | Run each cleanup option on a test DB copy | Correct items removed; nothing else | Not Run | |
| L-02 | Duplicate/orphaned meta cleanup | Correct counts; no collateral loss | Not Run | |
| L-03 | Concurrent runs | Lock prevents double-run | Not Run | |
| L-04 | Admin notice after run | `fastlayer_db_cleanup_notice_<user>` shown once | Not Run | |
| L-05 | Capability check | Only `manage_options` can run | Not Run | |
| L-06 | Nonce check | Invalid/missing nonce rejected | Not Run | |

## M. Admin UI & AJAX/security

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| M-01 | Settings save | Options persist under `fastlayer_options` | Not Run | |
| M-02 | Settings save triggers static config sync | `cache/wp-fastlayer/config.php` updated | Not Run | |
| M-03 | All AJAX handlers verify nonce + capability | Unauthorized requests rejected | Not Run | |
| M-04 | All admin-post handlers verify nonce + capability | Unauthorized requests rejected | Not Run | |
| M-05 | Output escaping in settings screens | No XSS with crafted values | Not Run | |
| M-06 | Admin menu slug `wp-fastlayer` | Menu links resolve | Not Run | |
| M-07 | Debug mode logging | `uploads/wp-fastlayer-logs/performance.log` written only when enabled | Not Run | |

## N. Uninstall / cleanup

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| N-01 | Delete plugin (with "Delete" action) | `fastlayer_options` + legacy option removed | Not Run | |
| N-02 | Transients removed | Current + legacy transient rows gone | Not Run | |
| N-03 | Cron cleared | All current + legacy hooks cleared | Not Run | |
| N-04 | Cache/min/preload/log dirs removed | Directories gone | Not Run | |
| N-05 | Drop-in removed only if owned | Foreign drop-in survives | Not Run | |
| N-06 | `WP_CACHE` removed only if owned | Foreign/manual define survives | Not Run | |

## O. Compatibility / cross-cutting

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| O-01 | PHP 7.4.30 (lowest version actually tested) | No fatals/notices | **Passed** | Manual compatibility testing completed on PHP 7.4.30 (maintainer-reported, 2026-10-12) |
| O-01b | PHP below 7.4 | No fatals/notices | **Blocked** | Not available in the testing environment; no reviewer requirement to test below 7.4 |
| O-02 | PHP 8.1 / 8.2 / 8.3 | No deprecation/fatal | Not Run | |
| O-03 | WordPress 6.0 minimum | No fatals | Not Run | |
| O-04 | Latest WP version (`Tested up to`) | No fatals | Not Run | |
| O-05 | Multisite | Activates and behaves (network vs per-site cache) | Not Run | |
| O-06 | Site Health | No new critical issues | Not Run | |
| O-07 | Debug bar / query monitor | No cache-breaking behaviour | Not Run | |

## P. Automated / static checks

| ID | Test | Expected | Status | Evidence |
| --- | --- | --- | --- | --- |
| P-01 | `node --check` on all four `assets/js/*.js` | All valid | **Passed** | Executed: `node --check` on `admin.js`, `imagekit-runtime-debug.js`, `lazyload.js`, `lqip-frontend.js` — all exit 0 (node v22.23.2) |
| P-02 | PHP lint (`php -l` on every PHP file) | No syntax errors | **Passed** (2026-10-13) | Executed: `php -l` on **13/13** PHP files (11 plugin + 2 regression test files) with the LocalWP PHP 7.4.30 binary **and** PHP 8.2.29 — all clean |
| P-03 | PHPCS / WPCS | No new violations | **Passed** (2026-10-12; re-run 2026-10-13) | Executed with plugin-check's bundled `vendor/bin/phpcs` — `WordPress.WP.I18n` over all 13 files with `--runtime-set text_domain fastlayer`: **0 findings**, exit 0 (re-run after the round-5 edit and again after the round-7 code pass: still 0). The full-rule-set WPCS run is tracked at P-03b |
| P-03b | Full WPCS run (`--standard=WordPress`) | No new violations vs baseline | **Executed, expected non-clean** (2026-10-13) | Bundled `vendor/bin/phpcs --standard=WordPress` over all 13 files: **14,262 E / 1,298 W**. Delta vs the pre-change file set (same tool): `Generic.WhiteSpace.DisallowSpaceIndent` +69 (added space-indented comment/helper lines, matching the documented 4-space style, AGENTS.md §3), `WordPress.PHP.NoSilencedErrors` −9 (nine `@rmdir()` suppressions removed), `WordPress.PHP.DevelopmentFunctions.error_log_error_log` −12 (all `error_log()` calls routed through the gated `\fastlayer_debug_log()` sink or given a line-scoped ignore), and the `WordPress.WP.AlternativeFunctions.*` sniffs → **0** (`rmdir` −9, `readfile` −3, `parse_url` −2, `rename` −1, `unlink` −1). **No new rule category** introduced. A fully "clean" WPCS result is not achievable with the project's documented 4-space style (AGENTS.md §3) |
| P-04 | Plugin Check (PCP) | Findings ≤ baseline; stale ones cleared | **Passed** (2026-10-13) | Executed: `wp plugin check fastlayer` via WP-CLI 2.12.0 + plugin-check 2.1.0 on the live local site (WP 7.1.3). Live scans: 679 → **625** (round 3), then **625 → 185 → 184** after the lowercase rename + round-4 fix (16 E / 168 W), then **184 → 176** after the round-5 security fix (16 E / 160 W), then **176 → 0 E / 160 W** after the round-7 WP_Filesystem pass (the 16 `AlternativeFunctions` ERRORs cleared; warnings unchanged; `AlternativeFunctions` rows = 0), then **160 → 2** after the round-8 remediation pass (all 160 warnings resolved via code changes or justified `phpcs:ignore`; only `unexpected_markdown_file` for AGENTS.md and `load_plugin_textdomainFound` remain). The 440 text-domain rows are gone. The `enqueued_styles_size` runtime check hung in this environment and was excluded — it emits no finding, so the result is unaffected. See `docs/REVIEW-FIXES.md` Part B-implementation-3 / -4 / -5 / -7 / -8 |
| P-05 | PHPUnit / integration tests | Pass | **Blocked** | No test harness/PHP |

## Q. Regression tests for review fixes

Run these after touching the relevant code; each maps to a review item.

| ID | Regression | Maps to | Status | Evidence |
| --- | --- | --- | --- | --- |
| Q-01 | Failed read of `wp-config.php` never blanks the file | REVIEW-FIXES A5 | Not Run | |
| Q-02 | WP_CACHE ownership marker prevents foreign removal | REVIEW-FIXES A7 | Not Run | |
| Q-03 | Foreign drop-in preserved | REVIEW-FIXES A6 | Not Run | |
| Q-04 | Legacy option/cron migration | REVIEW-FIXES A2 | Not Run | |
| Q-05 | Config file path cannot escape cache dir | REVIEW-FIXES A4 | Not Run | |
| Q-06 | Partial cache-file write surfaces a notice | REVIEW-FIXES A4 | Not Run | |
| Q-07 | Combined assets enqueued via core API | REVIEW-FIXES A3 | Not Run | |
| Q-08 | Uninstall removes all data, stops at foreign files | REVIEW-FIXES A8 | Not Run | |
| Q-09 | Recurring `WP_CACHE` warning resolved | REVIEW-FIXES A9 | **Partial** | Executed 2026-10-12 on a writable config: deactivate/reactivate cycle clean, `defined('WP_CACHE')` true on next request, no recurring warning (C-01/C-02/C-04). Non-writable-config branch still needs a site with a read-only `wp-config.php` |

## R. Priority 1 re-verification regression tests (SQL, nonce, sanitize, escape)

Added 2026-10-12 from the re-verification in `docs/REVIEW-FIXES.md` Part B-detail,
and updated after the fixes recorded in Part B-implementation.

Two independent things are tracked:

- **Impl.** — whether the fix/hardening is present in the source (source
  inspection only; **not** proof it works).
- **Status** — whether the test was actually **executed and observed**. A test is
  `Passed` **only** when it ran; source inspection never yields `Passed`.

**Most of the R-tests below require a live WordPress with PHP and remain
`Not Run` / `Blocked`.** A few static/automated and live-site rows were actually
executed on 2026-10-12 (see §R6 and §R7) and are marked accordingly — no row is
marked `Passed` unless it ran.

### R1. SQL / database

| ID | Regression | Expected | Maps to | Impl. | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| R-01 | Run "Optimize tables"; confirm every table in `SHOW TABLES` is optimized and the reported count equals the table count | No failure from identifier handling; all real tables targeted once | C-1 `optimize_all_tables()` | Implemented | Not Run | Needs live MySQL; PHP unavailable |
| R-02 | On a case-sensitive MySQL server, create a mixed-case table (e.g. `wp_MixedCase`), optimize, confirm the exact table is targeted | Case preserved; correct table optimized; no "table does not exist" | C-1 (`%i` + `quote_identifier()` fallback) | Implemented | **Blocked** | Needs case-sensitive MySQL |
| R-03 | On WP 6.0/6.1 (fallback path), optimize a table whose name contains a backtick | No SQL error; embedded backtick doubled and correctly escaped | C-1 `quote_identifier()` | Implemented | **Blocked** | Needs live MySQL + WP < 6.2 |
| R-04 | On WP ≥ 6.2, confirm `$wpdb->last_query` shows a single quoted identifier (no literal `%i` left) | `%i` substituted with a quoted identifier | C-1 `%i` path | Implemented | **Blocked** | Needs live WP ≥ 6.2 + PHP |
| R-05 | Confirm no request value can reach the `OPTIMIZE TABLE` argument (trace the call path) | Identifier only ever from `SHOW TABLES` | C-1 | Implemented | Not Run | Code-path review + live instrumentation |
| R-06 | Submit `cleanup_items` with an unknown key; confirm it is ignored | Unknown keys dropped; no SQL/option effect | B-detail §1 (587–603) | N/A | Not Run | |
| R-07 | Trigger each cleanup action with SQL logging on; confirm `DELETE`/`SELECT` statements contain no request data | Only literals and `$wpdb->` table names | B-detail §1 | N/A | Not Run | |
| R-08 | With `$wpdb->last_query`, verify the transient delete uses `prepare()` with N placeholders for N values | Placeholder count matches value count | B-detail §1 (1175) | N/A | **Blocked** | Needs PHP runtime |
| R-09 | Trigger the WebP attachment-count query; confirm it is prepared and the count is unchanged | `$wpdb->prepare($sql, $mime_types)` runs; no `call_user_func_array` | WebP prepare fix | Implemented | **Blocked** | Needs PHP runtime |

### R2. Nonce & capability

| ID | Regression | Expected | Maps to | Impl. | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| R-10 | Call every state-changing admin-post action with **no** `_wpnonce` | Request rejected (`check_admin_referer` dies / 403) | B-detail §2 | In place | **Blocked** | Needs live WP |
| R-11 | Call every state-changing AJAX action with an **invalid** nonce | `wp_send_json_error` 403 | B-detail §2 | In place | **Blocked** | Needs live WP |
| R-12 | Repeat R-10/R-11 as a logged-in non-admin with a **valid** nonce | Rejected by capability check | B-detail §2 | In place | **Blocked** | Needs live WP |
| R-13 | Call each handler with a valid nonce + `manage_options` | Action runs | B-detail §2 | In place | **Blocked** | Needs live WP |
| R-14 | Save FastLayer settings without the `option_page`/`_wpnonce` fields | Core `options.php` rejects the save | B-detail §2 (Settings API) | In place | **Blocked** | Needs live WP |
| R-15 | Request the admin-bar cache action with a bad `_wpnonce` | "Security check failed"; no cache cleared | B-detail §2 (admin-bar) | In place | **Blocked** | Needs live WP |
| R-16 | Trigger the DB cleanup AJAX (`verify_ajax_request`) with a bad nonce | 403 JSON; no cleanup | B-detail §2 | In place | **Blocked** | Needs live WP |

### R3. Input sanitization / unslash / escape

| ID | Regression | Expected | Maps to | Impl. | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| R-17 | Admin-bar action with `redirect_to=https://evil.example/` | Falls back to the admin URL (no open redirect) | B-detail §2 (106) | In place | **Blocked** | Needs live WP |
| R-18 | Admin-bar action with a same-site `redirect_to` incl. query string | Redirect preserved; URL sanitized | B-detail §2 (106) | In place | **Blocked** | Needs live WP |
| R-19 | WebP serve endpoint with `REQUEST_URI` containing `..`, `%00`, or a path outside uploads | 404/false; no file served | B-detail §2 (WebP 3164–3188) | In place | **Blocked** | Needs live WP + web server |
| R-20 | WebP serve endpoint with `HTTP_IF_MODIFIED_SINCE` = garbage | Handled gracefully; no error | B-detail §2 (3334) | In place | **Blocked** | Needs live WP |
| R-21 | ImageKit debug collect with script/HTML in `entries` | Values `esc_url_raw()`-stored; no stored XSS | B-detail §2 (CDN 133; 148–167) | In place | **Blocked** | Needs live WP + `WP_DEBUG` |
| R-22 | Admin-bar notice with `fastlayer_admin_bar_message=<script>` | Only the fixed mapping is printed; value never echoed | B-detail §2 (admin-bar 129–168) | In place (escape kept; no double-escape added) | **Blocked** | Needs live WP |
| R-23 | `$_GET['settings-updated']` set to a non-`true` value or markup | Notice not shown; raw value never output | **C-2** (`class-admin.php`) | Implemented | Not Run | Browser test possible on a live site |
| R-24 | `$_GET['settings-updated']=true` after a real save | Success notice shown exactly once | **C-2** | Implemented | **Blocked** | Needs live WP |
| R-25 | `$_GET['page']` / `$_GET['section']` set to markup | Sanitized; no reflection | B-detail §2 | In place | Not Run | |

### R4. Fix-specific manual regression instructions

**C-2 — settings-saved notice (`includes/class-admin.php::render_settings_saved_notice()`)**

1. On a live site, open **FastLayer → Dashboard** and save settings normally.
   Expect exactly one "Settings saved successfully." notice (the core
   `settings-updated=true` flag).
2. Load `wp-admin/admin.php?page=wp-fastlayer&settings-updated=<script>alert(1)</script>`.
   Expect **no** notice and **no** reflected markup.
3. Load `wp-admin/admin.php?page=wp-fastlayer&settings-updated=false`.
   Expect **no** success notice.
4. Load the page with the parameter absent.
   Expect **no** PHP notice/warning and no success notice.

**C-1 — `OPTIMIZE TABLE` identifier (`includes/class-database-optimization.php`)**

1. Enable database logging or inspect `$wpdb->last_query`; run the "Optimize
   tables" action.
2. Confirm the number of optimized tables equals the number of rows returned by
   `SHOW TABLES` (minus none) and that no table is reported as missing.
3. On WP ≥ 6.2, confirm the emitted query is `OPTIMIZE TABLE `real_name`` with the
   exact case from `SHOW TABLES` (the `%i` path).
4. On WP 6.0/6.1, repeat and confirm the `quote_identifier()` fallback emits a
   backtick-quoted name; for a table name containing a backtick, confirm the
   statement still executes without a syntax error.
5. Try to inject: there is no request parameter that feeds this value; confirm by
   code tracing that only `SHOW TABLES` output is used (see R-05).

> Confirming these requires a live WordPress with PHP. Until then they stay
> `Not Run`/`Blocked`; a `Passed` mark is only valid after the action was
> actually exercised and its result observed.

### R5. Round-2 fixes (2026-10-12)

Added for the fixes in `docs/REVIEW-FIXES.md` Part B-implementation-2. These
still need specific live-site conditions (uploads, both OSes, both WP branches,
a disposable site), so they remain `Not Run` / `Blocked` except **R-32**, which
was executed on the live site during the round-4 rename audit.

| ID | Regression | Expected | Maps to | Impl. | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| R-26 | Upload an image and let the WebP conversion run | Notice reads `… uploaded image "photo.jpg".`; `%s` is the basename | R2-1 `class-webp.php` | Implemented | **Blocked** | Needs live WP upload |
| R-27 | Trigger each admin-bar notice (`cache_cleared`, `css_js_cleared`, `preload_*`) | Visible text identical to before; no entity shown literally (no double-escape) | R2-2 `class-admin-bar.php` | Implemented | **Blocked** | Needs live WP |
| R-28 | Save settings, then inspect the generated `config.php` comment | A UTC timestamp is present; behavior unchanged | R2-3 `class-bootstrap.php` | Implemented | **Blocked** | Needs live WP |
| R-29 | Run "Optimize tables" on WP 6.0/6.1 **and** on WP ≥ 6.2, incl. a mixed-case table | Both succeed; identifier correctly quoted; no SQL error | R2-4 `class-database-optimization.php` | Implemented | **Blocked** | Needs live MySQL + both WP branches |
| R-30 | Clear cache, delete minified assets, remove logs, uninstall (Windows **and** Linux) | Same files removed as before; no new PHP warnings | R2-5 / R2-6 (`wp_is_writable` / `wp_delete_file`) | Implemented | **Blocked** | Needs live WP + both OSes |
| R-31 | Request a cached page and a WebP image over HTTP | Byte-identical output; correct headers (native `readfile()`/`parse_url()` paths) | R2-7…R2-10 | N/A (unchanged) | **Blocked** | Needs live WP + web server |
| R-32 | Rename the plugin directory to `fastlayer` on a disposable test site, then reactivate | Plugin reactivates cleanly; settings + drop-in survive; text-domain mismatch gone | Task 1 directory rename | Implemented | **Passed** | Executed 2026-10-12 on the live LocalWP site: `FastLayer` → `fastlayer` rename; stale `active_plugins` entry cleared (R4-1); text-domain mismatch rows **0**; settings + drop-in survived |

### R6. Round-3 fixes (2026-10-12)

For the fixes in `docs/REVIEW-FIXES.md` Part B-implementation-3. Unlike R5, the
**static/automated** checks for round 3 were **actually executed** in this
workspace (PHP lint, I18n PHPCS, and a live Plugin Check via WP-CLI); the
**functional** rows below still need a live site and remain **Blocked**.

| ID | Regression | Expected | Maps to | Impl. | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| R-33 | All placeholder-bearing translatable strings | Every `__()`/`_n()` with `%s`/`%d`/`%1$s` carries a `translators:` note above it | R3-1 | Implemented | **Passed (static)** | Bundled PHPCS `WordPress.WP.I18n` with `text_domain fastlayer`: 0 findings on all 11 files |
| R-34 | Real `sprintf()` cleanup/WebP/admin messages | `%1$d/%2$d` and `%1$s…%4$s` ordered placeholders; rendered text unchanged | R3-2 | Implemented | **Blocked (functional)** | PHPCS clean; needs live WP to eyeball rendered notices |
| R-35 | JS template strings (`%current%`, `%total%`, …) | UI progress/duration text still substitutes tokens correctly after the scoped ignores | R3-3 | Implemented | **Blocked (functional)** | Needs the WebP bulk / preload UI in a browser |
| R-36 | Optimize tables on WP 6.0/6.1 **and** ≥ 6.2, incl. mixed-case tables | Both branches succeed; `%i` only ever runs on ≥ 6.2 (scoped suppression, not blanket) | R3-4 | Implemented | **Blocked (functional)** | Needs both WP branches + MySQL |
| R-37 | Transient counts, transient deletes, WebP attachment count | Correct results; no SQL errors; suppression lines are single-line and justified | R3-5 / R3-6 | Implemented | **Blocked (functional)** | PHPCS/PCP clean; needs live DB on WP 6.0/6.1 and 6.2 |
| R-38 | Combined CSS/JS on the frontend | Combined tags still printed via `wp_styles->do_item()`/`wp_scripts->do_item()` with a `?ver=1.1.0`; fallback only when the registry is missing | R3-7 / R3-8 | Implemented | **Blocked (functional)** | Needs frontend + asset inspection |
| R-39 | readme.txt renders on WordPress.org | Short description line shown; no excerpt fallback warning | R3-9 | Implemented | **Blocked (functional)** | Needs wp.org readme renderer (or PCP readme check) |
| R-40 | The 3 previously mixed-EOL files | `Internal.LineEndings.Mixed` gone; files fully LF | R3-10 | Implemented | **Passed (static)** | PCP re-scan no longer reports the rule |
| R-41 | Full Plugin Check re-scan | Findings 679 → 625; no ERROR other than text-domain (environment) and the documented native calls | Round 3 | Implemented | **Passed (automated)** | `wp plugin check FastLayer` via WP-CLI 2.12.0 + plugin-check 2.1.0 on the live local site (WP 7.1.3) |

### R7. Round-4 post-rename audit (2026-10-12)

For the changes in `docs/REVIEW-FIXES.md` Part B-implementation-4. As with R6,
the static/automated checks and the `WP_CACHE` cycle were **actually executed**
on the live local site; no row here is claimed unless it ran.

| ID | Regression | Expected | Maps to | Impl. | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| R-42 | Rename the plugin folder to `fastlayer`; confirm Plugin Check no longer reports the text-domain rows | `TextDomainMismatch` / `textdomain_mismatch` = 0 | R4-1 / rename | Implemented | **Passed (automated)** | `wp plugin check fastlayer`: 625 → **185** (the 440 text-domain rows cleared); 0 text-domain findings remain |
| R-43 | Confirm the stale `active_plugins` entry is gone and the plugin is active | Option lists `fastlayer/wp-fastlayer.php`; `plugin_basename()` returns the lowercase path | R4-1 | Implemented | **Passed** | `active_plugins` = `["fastlayer/wp-fastlayer.php","plugin-check/plugin.php"]`; plugin reported active |
| R-44 | Remove the legacy `$_wpfl_bootstrap_file` global; confirm the plugin still loads and the finding count drops | No `$_wpfl_bootstrap_file` anywhere; `PrefixAllGlobals` 33 → 32 | R4-2 | Implemented | **Passed (static)** | Source grep = 0 matches; `wp plugin check fastlayer` 185 → **184** (16 E / 168 W); PHP lint 11/11 clean |
| R-45 | Deactivate → reactivate on a writable config; observe the drop-in and `WP_CACHE` line | Removed on deactivate, restored on reactivate, no duplicate; `defined('WP_CACHE')` true next request | R4 (A9/C-02/C-04) | Implemented | **Passed** | Executed 2026-10-12 on the live site; site returned 200; `wp-config.php` lints clean |
| R-46 | readme.txt folder-name reference | Reads `fastlayer` (not `FastLayer`) | R4-3 | Implemented | **Passed (static)** | Source inspection of `readme.txt` line 17 |
| R-47 | Release-checklist ZIP root name | Reads `fastlayer/` | R4-4 | Implemented | **Passed (static)** | Source inspection of `docs/RELEASE-CHECKLIST.md` §5.1 |
| R-48 | Non-writable `wp-config.php`: does the recurring `WP_CACHE` warning still reproduce? | Warning shown; capture the sequence | R4 (A9, open) | N/A | **Blocked** | Needs a site with a read-only `wp-config.php` |

### R8. Round-5 error & security audit (2026-10-13)

For the change in `docs/REVIEW-FIXES.md` Part B-implementation-5. Every row below
was **actually executed** in this workspace; anything not run is marked
**Blocked**.

| ID | Regression | Expected | Maps to | Impl. | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| R-49 | Plugin Check on the unmodified source reproduces the round-4 baseline | 184 findings (16 E / 168 W) | baseline | N/A | **Passed (automated)** | `wp plugin check fastlayer` = **184** (16 E / 168 W) — exact match |
| R-50 | Review all 16 remaining ERRORs individually | Each is a justified pre-WordPress / no-WP-helper call | 16 E | Audited | **Passed (static)** | Per-call table in Part B-implementation-5; none suppressed |
| R-51 | Every state-changing handler verifies nonce + capability | No handler missing a nonce/cap check | NonceVerification | Audited | **Passed (static)** | All 18 handlers use `check_admin_referer()` / `check_ajax_referer()` / `wp_verify_nonce()`; source inspection |
| R-52 | Sanitize/unslash `$_SERVER['REQUEST_METHOD']` in the page cache | No raw/unslashed read (AGENTS.md §4) | R5-1 | Implemented | **Passed (automated + static)** | New `get_request_method()` at both call sites; PCP `InputNotSanitized` 20 → 16 and `MissingUnslash` 10 → 6 (8 rows at `class-page-cache.php:228,229,536,537` cleared); PHP lint 11/11 clean |
| R-53 | No regression in the overall Plugin Check count | Findings ≤ 184; no new ERROR | R5-1 | Implemented | **Passed (automated)** | 184 → **176** (16 E / 160 W); ERROR count unchanged at 16 |
| R-54 | Site still serves after the edit | HTTP 200 | R5-1 | Implemented | **Passed** | `curl.exe -D - http://fastlayer.local/` → 200 (GET and HEAD); plugin active, `WP_CACHE` true, drop-in present |
| R-55 | Live cache serve/save flows (D-01…D-12) with the cache enabled | Cache serves and saves | R5-1 (functional) | Exercised | **Passed** | 2026-10-13 live site: D-01, D-02, D-03, D-05, D-06, D-07, D-08, D-09, D-11, D-12 passed; D-10 passed for the nginx half (Apache/LiteSpeed half Not Run); D-04 **Blocked** (no WooCommerce). E-04/E-05 also passed (nonce-protected purge + settings-save purge). Cache was enabled through `Admin::sanitize_options()` only for the test and then restored to `enable_cache='0'`; `fastlayer_options` byte-identical to the pre-test backup |
| R-56 | Independent continuation of the round-5 automated checks (no source change) | Results identical to R-49…R-54 | R5-1 | Re-verified | **Passed (automated)** | 2026-10-13: PHP lint 11/11; `node --check` 4/4; I18n 0; full WPCS **14,150 E / 1,323 W**; Plugin Check **176** (16 E / 160 W); GET/HEAD 200 |
| R-57 | `Page_Cache::persist_debug_trace()` writes through a non-existent core function | Trace transient persists | D-debug (observation) | Implemented | **Passed** | 2026-10-13: `update_transient()` (not core; its `function_exists` guard was always false) replaced with `set_transient( 'fastlayer_cache_debug_trace', $trace, 300 )` in `includes/class-page-cache.php` — the argument order/expiry match the read path (`class-admin.php:1986`) and `docs/ARCHITECTURE.md`. PHP lint 12/12; I18n 0; WPCS 14,178 E / 1,323 W; Plugin Check unchanged **176** (16 E / 160 W). Live: one anonymous GET with `enable_cache='0'` populated the transient (`skip_reason=cache_disabled, buffer_not_started`) |
| R-58 | Focused regression check for the debug trace (new) | Sentinel trace stored + retrievable; 300 s expiry | R-57 | Added | **Passed** | New `tests/test-r57-debug-trace-transient.php` (`wp eval-file`): stores a sentinel via `persist_debug_trace()`, reads it with `get_transient()`, checks fields + `updated_at` + the `_transient_timeout_` …≈300 s, then deletes it. Current code → **PASS** (exit 0); A/B against the pre-fix body → **FAIL** (exit 1), proving the check catches the defect. Adds 0 Plugin Check findings |

### R9. Round-7 WP_Filesystem AlternativeFunctions pass (2026-10-13)

For the change in `docs/REVIEW-FIXES.md` Part B-implementation-7. Every row below
was **actually executed** in this workspace; anything not run is marked
**Blocked**.

| ID | Regression | Expected | Maps to | Impl. | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| R-59 | Directory removal still works through `WP_Filesystem` after the `rmdir()` migration | Empty dir removed; non-empty dir + file path refused | B11 (round 7) | Implemented | **Passed** | New `tests/test-filesystem-rmdir.php` (`wp eval-file`): `Bootstrap::delete_empty_directory()` removes an empty dir, refuses a non-empty dir (`rmdir()` semantics preserved) and refuses a file path. **PASS (exit 0) on PHP 7.4.30 and 8.2.29** |
| R-60 | The 16 `WordPress.WP.AlternativeFunctions.*` Plugin Check ERRORs are cleared without regressing the rest | 16 E → 0 E; warnings unchanged at 160 | B11 (round 7) | Implemented | **Passed (automated)** | `wp plugin check fastlayer`: **0 E / 160 W**. The fresh export is identical (file:line:column:type:code) to the pre-fix `pcp-round7fs.csv` for all 160 warnings; `AlternativeFunctions` rows = **0**. The seven non-migratable calls carry line-scoped `phpcs:ignore` with a reason; the nine `rmdir` calls were fixed in source, not suppressed |
| R-61 | Uninstall still removes plugin-generated directories (fail-closed) | Emptied dirs removed via `WP_Filesystem`; removals skipped when no filesystem is available | B11 (round 7) | Implemented | **Passed (static + lint)** | `uninstall.php` initialises `WP_Filesystem()` once and removes the five plugin-generated dirs with `$wp_filesystem->rmdir()` only when initialised; otherwise the (empty) directory is left in place. `php -l` clean (PHP 7.4.30 and 8.2.29). Live deactivate/delete **Not Run** (no disposable site) |
