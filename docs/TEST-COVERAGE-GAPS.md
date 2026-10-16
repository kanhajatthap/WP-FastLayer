# TEST-COVERAGE-GAPS.md — FastLayer test coverage and gaps

**Recorded:** 2026-10-15 (round-9 runtime-validation pass). This file separates
what is **executed**, what is **source inspected**, and what is **blocked**, so no
unrun check is ever reported as passing.

## 0. Environment used for the round-9 run

| Item | Value |
| --- | --- |
| Live site | `http://fastlayer.local` (LocalWP; **nginx** front end) |
| WordPress | **7.1.3** |
| PHP (web + CLI) | **7.4.30** (`%APPDATA%\Local\lightning-services\php-7.4.30+6\bin\win64`) |
| MySQL | 8.4.0, port **10035** |
| WP-CLI | 2.12.0 (`wp-cli.phar`) |
| Plugin Check | 2.1.0 (installed on the live site) |
| Scratch site | Disposable second WP 7.1.3 (temp dir) + multisite + WooCommerce 11.2.1 |
| Node | v22.23.2 |

Every WP-CLI command below was run as (paths shortened for readability):

```
php -d extension_dir=<php74>\ext -d extension=php_mysqli.dll -d extension=php_gd2.dll \
    -d mysqli.default_port=10035 <wp-cli.phar> \
    --path="C:\Users\india\Local Sites\fastlayer\app\public" <command>
```

> **Site hygiene.** The runtime probes temporarily changed four options
> (`enable_image_delivery`, `enable_imagekit_cdn`, `imagekit_endpoint`,
> `enable_cache`), created one test page (ID 12) and one imported attachment
> (ID 11, plus generated `.webp` siblings), and briefly enabled preload. All of
> these were reverted/removed at the end of the pass; the live options, cron and
> uploads are back to their original state (verified).

---

## 1. Executed (automated / runtime)

| Check | Command | Result (2026-10-15) |
| --- | --- | --- |
| PHP syntax | `php -l` on every `.php` in the plugin | **21/21 clean** |
| JS syntax | `node --check assets/js/*.js` | **4/4 clean** |
| Plugin Check | `wp plugin check fastlayer --exclude-directories=.github,.opencode,docs,tests,node_modules,vendor` | **0 ERROR / 3 WARNING** |
| WPCS (WordPress standard) | bundled `phpcs --standard=WordPress --extensions=php --ignore='*/vendor/*,*/node_modules/*,*/tests/*' --report=json .` | 57 sniffs; report produced |
| WPCS delta gate | `php tests/phpcs-gate.php <report.json> tests/phpcs-baseline.json` | **PASS** — no new rule category |
| Gate self-test (A/B) | gate against a synthetic new `WordPress.Security.NonceVerification.Missing` | **exit 1** (catches regressions) |
| Gate self-test (B) | gate against a synthetic `Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed` | **exit 0** (formatting ignored, as documented) |
| Version sync | header vs `FASTLAYER_VERSION` vs `Stable tag` | **1.1.0 == 1.1.0 == 1.1.0** |
| PHPUnit | PHPUnit 9.6.38 phar on PHP 7.4.30, bootstrapped against a real `wp-load.php` | **OK (7 tests, 14 assertions, exit 0)** |

### 1.1 HTTP smoke (live site, `curl.exe`)

| Request | Result |
| --- | --- |
| `GET /` (cold) | `200`, 4.3–5.3 s |
| `GET /` (warm) | `200`, 0.012–0.16 s, `X-Cache-Status: HIT`, `X-Cache-Generated: yes`, `Cache-Control: public, max-age=36000` |
| `HEAD /` | `200` |
| `GET /hello-world/`, `GET /sample-page/` | `200` |
| `GET /?p=99999` | `404` (and **not** cached) |
| `GET /?s=hello` | `200` |
| `GET /feed/`, `GET /robots.txt`, `GET /wp-login.php` | `200` |
| `POST /wp-login.php` | `200` (dynamic request, not cached) |
| Unexpected output in served HTML | **none** — no `Notice:`/`Warning:`/`Fatal error`/`Deprecated:`; the only `undefined` hits are inside minified JS (`typeof … "undefined"`) |

### 1.2 Page cache (live)

| Check | Result |
| --- | --- |
| Cache dir created at `wp-content/cache/wp-fastlayer/` | **yes** (`.html` entries + `index.php` + `.htaccess`) |
| Minification on a cached page | **verified** — 0 HTML comments, whitespace collapsed |
| Cache invalidation on post edit | **verified** — editing post 1 purged its cached file; title restored |
| Static config format | **`config.json`** (JSON only); **no legacy `config.php`** |
| Self-heal of a deleted `config.json` | **verified** — `ensure_static_config()` recreated it on the next request |
| After reactivation the deployed `advanced-cache.php` matches the plugin template | **verified** (sha1 `59e699d509ed…`) |

### 1.3 WebP (live, end-to-end)

| Check | Result |
| --- | --- |
| GD WebP support | **yes** (`Imagick`: no) |
| `wp media import` of a GD-made JPEG (attachment 11) | **generated a `.webp` sibling for every size** |
| Delivery with `enable_image_delivery='1'` | **`<picture data-wp-fastlayer-picture="1"><source type="image/webp" srcset=…>` on the front end** (4 WebP references), both in WP context and over HTTP |
| Direct `*.jpg` request negotiation | **Blocked** — nginx serves the static file before PHP runs (see §4) |

### 1.4 CDN / ImageKit (live, end-to-end)

| Check | Result |
| --- | --- |
| `CDN::rewrite_url()` with ImageKit enabled | **verified** — `https://ik.imagekit.io/…?tr=f-auto,q-auto,w-1024` |
| Front-end markup after the round-9 module fix | **5 `ik.imagekit.io` references** in the served HTML; attributes well-formed |
| Escaping of generated attribute URLs | **verified** — raw `&` only occurs inside JS (`&&`) and HTML entities (`&#8211;`, `&#038;`, `&raquo;`); no unescaped attribute URL |

### 1.5 Preload (live) — PARTIAL

| Check | Result |
| --- | --- |
| Queue built from the site sitemap | **yes** — 5 URLs discovered |
| Batch loop + state tracking (`fastlayer_preload_state`) | **verified** — `total/processed/succeeded/failed`, reschedules the batch |
| Successful warm | **1 page warmed and cached** (`succeeded=1`, a 67 490-byte cache file appeared) |
| Failures | 3 URLs failed with `fsocket timed out` / `cURL error 28 … timed out after 5004 milliseconds` because a **cold** render on this Local host takes **4.3–5.3 s** and the plugin caps each URL at `PRELOAD_URL_TIMEOUT = 5` s |

### 1.6 Fresh install / activation / deactivation (scratch site)

| Check | Result |
| --- | --- |
| Fresh WP 7.1.3 + plugin activate | drop-in installed, **`define( 'WP_CACHE', true ); // wp-fastlayer:owned-wp-cache`** added, cache dir created, `config.json` written |
| Deactivate | **only** the owned `WP_CACHE` line removed (the site's own `WP_CACHE_KEY_SALT` untouched) and the drop-in removed |
| Reactivate | owned line re-added and the drop-in re-copied (now byte-identical to the plugin template) |
| All 9 regression scripts on the fresh install | **9/9 PASS** |

### 1.7 Upgrade from 1.0.0 (scratch site)

| Check | Result |
| --- | --- |
| Legacy `wp_fastlayer_options` migrated to `fastlayer_options` | **yes** (a marker value survived) |
| Defaults filled (`wp_parse_args`) and `_version` bumped | **yes** → `1.1.0` |
| Legacy cron `wp_fastlayer_preload_cache` cleared, new `fastlayer_preload_cache` scheduled | **yes** |
| `mirror_legacy_options()` keeps the rows in sync | **yes** |
| `config.json` regenerated | **yes** |

### 1.8 Multisite (scratch site) — PASS (WP-CLI level)

| Check | Result |
| --- | --- |
| `wp core multisite-convert` + network activation | **works** — no fatal; drop-in shared; `sites.json` + `sites/1.json` written and **no shared `config.json`** remains |
| Subsite created; plugin loaded on main site and subsite | **verified** (blog 1 and blog 2) |
| Per-site option storage vs a single network-wide config | **FIXED** — `sites.json` maps `http://scratch.local → 1` and `http://scratch.local/site2 → 2`; each site has its own `sites/{id}.json`; two sites holding `MAIN_ONLY` / `SUB_ONLY` + different `enable_cache` stay isolated. Finding **J** in `docs/AUDIT-FINDINGS.md`; decision in `docs/ADR-0001-multisite-config.md`. |
| Resolver unit checks (synthetic index) | **PASS** — subdomain, mapped domain, nested subdirectory (longest-path wins), segment boundary (`/blog` does **not** match `/blogger/`), port-suffixed host, unknown host → 0 |
| Home URL change (round 10) | **PASS** — changing a subsite's `home`/`siteurl` re-indexes the new URL and drops the old entry (`update_option_home`/`update_option_siteurl` hook); a change that bypasses the hook is recovered on the next `init` |
| Malformed / lost index (round 10) | **PASS** — a `sites.json` of invalid JSON is rebuilt from the sites table without dropping the surviving site; an index entry removed for the current site is restored on `init` without disturbing the other site |
| Corrupt per-site file (round 10) | **PASS** — a corrupt `sites/{id}.json` is rewritten on `init`; on single-site a corrupt `config.json` is likewise repaired |
| No write loop | **PASS** — after the fix, a healthy request leaves `config.json`/`sites.json`/`sites/{id}.json` mtimes unchanged (no per-request rewrite) |
| Note | `wp core multisite-convert` rewrote `wp-config.php` and dropped the owned `WP_CACHE` line; reactivating the plugin restored it. Observed only with that WP-CLI command, not in normal admin flows. |
| Not exercised | HTTP-level multisite front-end flows (the scratch network has no web server); only a **subdirectory** network was stood up locally — the subdomain case is covered by the synthetic resolver test. |

### 1.9 WooCommerce (scratch site, network-activated)

| Check | Result |
| --- | --- |
| WooCommerce 11.2.1 active + FastLayer loaded | **no fatal** |
| FastLayer CDN hook on `woocommerce_single_product_image_html` | **attached** |
| Product create → `wc_get_product()` → `get_image()` | **works** (`WC_Product_Simple`, non-empty image HTML) |
| All 9 regression scripts with WooCommerce active | **9/9 PASS** |
| `Optimizer::is_woocommerce_context()` | `false` in a CLI context (no front-end query) — **not** a defect; not assertable without a real request |

### 1.10 Regression scripts (all executed via `wp eval-file`, exit 0)

| Script | Proves | Finding |
| --- | --- | --- |
| `tests/test-url-to-path.php` | Internal URLs map; traversal/NUL/foreign hosts rejected | A |
| `tests/test-config-json.php` | `config.json` written + round-trips; legacy `config.php` removed | B |
| `tests/test-buffer-flush.php` | Guarded flush runs callbacks in order; never closes a foreign buffer | D |
| `tests/test-option-type-strictness.php` | Every toggle reader accepts both the string and integer forms of `1` | H (new) |
| `tests/test-cdn-module-loaded.php` | `FastLayer\CDN` is instantiated and its front-end hooks are attached | I (new) |
| `tests/test-filesystem-rmdir.php` | `delete_empty_directory()` removes empty dirs, refuses non-empty/file | R7 |
| `tests/test-r57-debug-trace-transient.php` | Debug trace stored, retrievable, ~300 s expiry | R6 |
| `tests/test-webp-htaccess-scope.php` | `.htaccess` cleanup strips only FastLayer directives | — |
| `tests/test-multisite-config.php` | Multisite: per-site config + `sites.json` index isolate two sites; resolver matches each home URL / child path; home-URL change re-indexed; malformed index rebuilt; lost entry restored; corrupt per-site file repaired (single-site branch asserts `config.json` authoritative and a corrupt one is repaired) | J (new) |

**A/B proof for the new scripts.** With `advanced-cache.php` and `includes/`
reverted to the committed (pre-fix) bodies, `test-cdn-module-loaded.php` fails
**6 checks** and `test-option-type-strictness.php` fails **15 checks**. For
`test-multisite-config.php`, reverting the finding-J recovery logic
(`ensure_static_config()` validation, `update_site_index()` rebuild/prune, and the
`update_option_home`/`update_option_siteurl` hooks) makes it fail **7 checks**
(home-URL change not indexed, stale entry kept, malformed index truncated, lost
entry not restored, corrupt file not repaired); reverting the whole finding to
`HEAD` also fails. With the fixed bodies restored all pass. All reverted files
were restored and verified.

---

## 2. Source-inspected only (no runtime proof)

| Item | Why inspection only |
| --- | --- |
| Log location (finding E) | Confirmed by reading the logging helper; the plugin logs to `wp-content/uploads/wp-fastlayer-logs/performance.log`, not the plugin dir. No log write was triggered. |
| Inline script/style usage (finding F) | Confirmed by reading the `wp_add_inline_*` calls + Plugin Check (0 `NonEnqueued*`). |
| Legacy `advanced-cache.php` `config.php` fallback read | Reachable only for a pre-1.1.0 install that has not yet self-healed. |
| Multisite subdomain layout | A live **subdirectory** multisite was exercised via WP-CLI (finding J); the subdomain / mapped-domain / segment-boundary cases are covered by the resolver's synthetic-index unit checks, not by a live subdomain network. |

---

## 3. Blocked / Not Run (explicit gaps)

| Gap | Why blocked | Would unblock with |
| --- | --- | --- |
| WordPress 6.0 runtime | No disposable WP 6.0 site; only the static `wp_functions_compatibility` check passes | A WP 6.0 environment |
| Apache / LiteSpeed cache serving | Only nginx is installed here | Apache/LiteSpeed host |
| Direct `*.jpg` → WebP negotiation | nginx serves the static image before PHP runs | An Apache/LiteSpeed host, or a PHP front controller |
| PHP below 7.4 | Not available; no reviewer requirement | A PHP 7.3 environment (not required) |
| PHP 8.1 / 8.3 | Only 7.4.30 and 8.2.29 available here | More PHP runtimes |
| Preload completing a full queue | Cold loopback renders (4.3–5.3 s) exceed the plugin's 5 s per-URL cap on this host | A host where a cold render is < 5 s |

---

## 4. QA-checklist mapping

The full manual matrix is `tests/QA-CHECKLIST.md`. High-level status after the
round-9 run:

| Section | Area | Status |
| --- | --- | --- |
| A | Activate / deactivate / reactivate | **PASS** (live + fresh scratch install, incl. drop-in + owned `WP_CACHE`) |
| C | `WP_CACHE` warning | **Partial** (writable path verified; non-writable config not run) |
| D/E | Page cache serve/save/invalidate | **PASS** (serve, save, invalidate, headers, 404 not cached, POST bypass) |
| F | Preload | **Partial** — mechanism verified end-to-end (queue, batch, 1 page warmed); 3/timeouts due to the 5 s cap on a cold local render |
| G/H | CSS/JS optimize | **Partial** (minify + comment/whitespace removal verified; combine not asserted) |
| I | Lazy load / LQIP | Not Run (disabled by default on this site) |
| J | WebP | **PASS** (generation + delivery end-to-end) |
| K | CDN / ImageKit | **PASS** (rewriting + escaping end-to-end) |
| L | Database optimization | Not Run |
| N | Uninstall cleanup | Not Run |
| R | Priority-1 security regression | **Partial** (static + 9 regression scripts + PHPUnit executed) |

---

## 5. Rule

If a row is not in §1, it is **not** a pass. Do not upgrade a §2/§3 row to
“passes” without an actual run.
