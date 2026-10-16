# AUDIT-FINDINGS.md — FastLayer remediation findings

**Audit date:** 2026-10-15
**Plugin version:** `1.1.0` (unchanged — see below)
**Baseline commit:** `1713fff` (branch `main`)
**Scope:** the seven areas named in the current review (A–G below), a
whole-plugin pass, four findings surfaced by the round-9 **runtime** validation
(H–K), and the round-10 recovery hardening inside finding J, all implemented and
verified in this workspace.

> **Provenance note.** No review email/message exists in the repository. The
> findings below are the authoritative task list supplied for this pass, matched
> to the current source. Every status is backed by an executed command or by
> explicit source inspection; the method is stated for each row. Nothing here
> claims a test that was not run.

## Why the version did not change

All changes are internal remediation of the existing `1.1.0` code (no new
feature, no public API/option/filter/shortcode/slug/text-domain change), so the
version stays `1.1.0`. The three version locations remain in sync (verified:
header `1.1.0`, `FASTLAYER_VERSION` `1.1.0`, `Stable tag: 1.1.0`).

## Summary

| # | Area | Primary location | Root cause | Fix | Verification method | Status |
| --- | --- | --- | --- | --- | --- | --- |
| A | URL → filesystem path conversion | `includes/class-bootstrap.php`, delegated in `class-admin.php`, `class-optimizer.php`, `class-webp.php` | Three independent `url_to_path()` implementations, each assuming `site_url()` == `ABSPATH`; no traversal or host check | One canonical `Bootstrap::url_to_path()` + `path_has_traversal()` + `url_host_is_internal()`; all callers delegate | `tests/test-url-to-path.php` **executed PASS**; Plugin Check **0 E** | **Fixed** |
| B | Executable config under `wp-content/cache` | `includes/class-bootstrap.php`, `advanced-cache.php` | `write_static_config()` wrote an executable `config.php` that the drop-in `include`d | Write `config.json` (JSON, byte + read-back verified); delete legacy `config.php`; `ensure_static_config()` self-heals; drop-in reads JSON with a legacy `include` fallback | `tests/test-config-json.php` **executed PASS**; live site shows `config.json`, no `config.php` | **Fixed** |
| C | Unescaped generated URLs / attributes | `includes/class-cdn.php` | Broken `srcset` regex `["\"]`; generated URLs inserted into HTML attributes without escaping; CSS `url()` unsanitized; LCP re-injection not escaped | Regex `["\']`; `esc_attr()` at insertion; new `sanitize_css_url()`; `apply_lcp_attributes_to_img_tag()` uses `preg_replace_callback` + `esc_attr( html_entity_decode( … ) )` | Source inspection; Plugin Check **0 E** (no `EscapeOutput`) | **Fixed** |
| D | Unclosed / unordered output buffers | `includes/class-optimizer.php`, `includes/class-page-cache.php` | Buffers opened for minify/cache had no guaranteed, ordered flush | `Optimizer::flush_html_buffer()` (shutdown prio 1) and `Page_Cache::flush_output_buffer()` (shutdown prio 5), each with a `buffer_level` guard | `tests/test-buffer-flush.php` **executed PASS**; source inspection | **Fixed** |
| E | Log files inside the plugin directory | `includes/class-bootstrap.php`, logging helper | Reported as logs written inside the plugin folder | Not reproducible: logs already go to `uploads/wp-fastlayer-logs/performance.log`; the legacy `logs/` dir is removed on upgrade | Source inspection | **No defect (verified)** |
| F | Inline scripts/styles bypass the enqueue API | `includes/class-admin.php`, `class-optimizer.php` | Reported as raw inline `<script>`/`<style>` prints | Not reproducible: code uses `wp_add_inline_script()`, `wp_print_inline_script_tag()`, `wp_add_inline_style()` | Source inspection; Plugin Check **0** `NonEnqueued*` | **No defect (verified)** |
| G | Whole-plugin audit | whole plugin | — | Confirmed the retained findings and classified each | Plugin Check + WPCS delta | See “Retained findings” |
| H | Toggle options read with `'1' ===` strict compare (runtime) | `includes/class-webp.php`, `class-optimizer.php`, `class-page-cache.php`, `class-cdn.php`, `class-admin.php`, `class-bootstrap.php`, `advanced-cache.php` | Readers used `'1' === $options[...]`, but some paths (`wp option patch`/`update_option` with an int) store the **integer** `1`, so an enabled feature silently read as disabled | Normalize every toggle read with `(string)` before the compare | `tests/test-option-type-strictness.php` **executed PASS** (A/B: fails **15 checks** pre-fix); live WebP + CDN re-tested end-to-end | **Fixed** |
| I | `FastLayer\CDN` never instantiated (runtime) | `includes/class-bootstrap.php` (`load_modules()`) | The `CDN` class was written and its hooks defined, but `load_modules()` never created it, so the whole CDN/ImageKit feature was dead code | Added `'CDN'` to the module list in `load_modules()` | `tests/test-cdn-module-loaded.php` **executed PASS** (A/B: fails **6 checks** pre-fix); live `<img>`/`<picture>` rewriting verified | **Fixed** |
| J | Multisite: one network-wide `config.json` for per-site options | `includes/class-bootstrap.php`, `advanced-cache.php`, `includes/class-page-cache.php`, `uninstall.php` | `FASTLAYER_CACHE_DIR` is shared across the network while `fastlayer_options` are per site, so the last site to save wins for **every** site on the network | Per-site `sites/{blog_id}.json` + a `sites.json` home-URL→id index that the drop-in resolves pre-bootstrap (longest host+path match); single-site keeps `config.json`; cache clears now touch only `.html` so config/index survive; uninstall is per-site and recursive | `tests/test-multisite-config.php` **executed PASS** on a scratch 2-site network **and** on the live single-site; **A/B fails 7 checks** pre-fix; decision recorded in `docs/ADR-0001-multisite-config.md` | **Fixed** |
| K | WPCS gate allow-list never matched its own sniffs | `tests/phpcs-gate.php` | The allow-list stored bare sniff names but PHPCS reports message-level codes, so every formatting entry was dead and a formatting-only addition failed the gate | Match an entry when the source equals it **or** starts with `entry . '.'`; added an A/B self-test | Gate re-run **PASS**; synthetic non-formatting finding still **exit 1** | **Fixed** |

**Net quality-gate effect:** Plugin Check is **0 ERROR / 3 WARNING** (all three
warnings are packaging/environment-level, not code defects), and the WPCS
per-sniff delta introduces no new rule category (verified after finding K).

---

## A — URL → filesystem path conversion

**Finding.** `Admin`, `Optimizer` and `Webp` each carried a private
`url_to_path()` that mapped a URL to a local path by stripping `site_url()` and
prefixing `ABSPATH`. That is wrong whenever the content directory is custom, the
site is installed in a subdirectory, or a URL points at uploads/plugins. None of
the copies rejected `../` traversal or a foreign host, so a crafted URL could be
mapped outside the intended directory before a file operation.

**Root-cause fix.**

- `Bootstrap::url_to_path()` (`includes/class-bootstrap.php:435`) is now the one
  implementation. It:
  - rejects non-strings/empty input and runs `esc_url_raw()`;
  - requires `wp_parse_url()` to yield a path;
  - rejects a **foreign host** via `Bootstrap::url_host_is_internal()`
    (`:551`), which compares against `home_url()`/`site_url()` hosts;
  - rejects traversal/NUL bytes via `Bootstrap::path_has_traversal()` (`:526`),
    treating backslashes as separators too;
  - resolves against the **longest matching base** among content, includes,
    admin, plugins, uploads and the site root, so custom directories and
    subdirectory installs map correctly.
- `Admin::url_to_path()`, `Optimizer::url_to_path()` and `Webp::url_to_path()`
  now simply delegate to `Bootstrap::url_to_path()`.

**Verification.** `tests/test-url-to-path.php` executed via `wp eval-file`
(PASS) exercises internal URLs, foreign hosts, `../` traversal and subdirectory
bases. Plugin Check reports no new finding.

---

## B — Executable config under `wp-content/cache`

**Finding.** `Bootstrap::write_static_config()` generated a PHP file
(`.../cache/wp-fastlayer/config.php`) that `advanced-cache.php` then `include`d.
Anything under `wp-content/` that a request can reach should not be executable
by an `include`, and a partially-written PHP file could fatal the drop-in.

**Root-cause fix.**

- `write_static_config()` (`includes/class-bootstrap.php:355`) now encodes the
  options with `wp_json_encode()` and writes **`config.json`**:
  - target path is forced inside the cache directory (`:373`);
  - the write is byte-counted (`:384`) and the on-disk content is read back and
    compared (`:390`);
  - any failure is recorded via `record_write_failure()` (`:374, :380, :386,
    :391`) rather than silenced;
  - the legacy executable `config.php` is deleted (`:399`).
- `ensure_static_config()` (`:169`, called from `init()`) regenerates
  `config.json` and removes a lingering `config.php`, so an upgraded install
  self-heals on the next request without a settings save.
- `advanced-cache.php` reads `config.json` with
  `file_get_contents()` + `json_decode()` (`advanced-cache.php:20-25`). It keeps
  a **legacy `include` fallback** for an install that has not yet regenerated
  the JSON (`:36-38`) — see “Retained findings”.

**Verification.** `tests/test-config-json.php` executed via `wp eval-file`
(PASS) asserts JSON content, byte/read-back verification, and legacy-file
removal. On the live site, `wp-content/cache/wp-fastlayer/config.json` exists
with valid content and no `config.php`.

---

## C — Unescaped generated URLs / attributes

**Finding.** CDN/ImageKit rewriting built markup by string insertion:

- the `srcset` regex used `["\"]`, a broken character class that did not match
  single-quoted attributes and could mis-capture;
- rewritten URLs were inserted into `src`, `srcset`, `data-*` and CSS `url()`
  without output escaping;
- the LCP attribute injector could re-inject a value that was not
  attribute-escaped, and could double-encode an existing entity.

**Root-cause fix (`includes/class-cdn.php`).**

- `srcset`/`data-srcset` regex switched to `["\']` (`:325`).
- Generated URLs are escaped at the point they are written into an attribute
  (`esc_attr()` at `:361`, `:926`; `esc_attr()` for the LCP preload `href`,
  `imagesrcset`, `imagesizes` at `:57-62`).
- New `sanitize_css_url()` (`:1121`) strips characters that could close a CSS
  `url()` token (`"` `'` `(` `)` `\` `<` `>` and control whitespace) before the
  value is emitted in a style context.
- `apply_lcp_attributes_to_img_tag()` (`:1628`) now uses
  `preg_replace_callback()` and writes
  `esc_attr( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) )`, so a value is
  decoded once and escaped exactly once.

**Verification.** Source inspection of each insertion point; Plugin Check
reports **0** `WordPress.Security.EscapeOutput.OutputNotEscaped` findings.

---

## D — Unclosed / unordered output buffers

**Finding.** The optimizer opens an output buffer to minify HTML and the page
cache opens one to capture the page. If the buffer is not flushed, the response
is truncated; if a foreign buffer opened on top is flushed, another plugin's
output is lost; if flushing is unordered, the cache can store unminified HTML.

**Root-cause fix.**

- `Optimizer::flush_html_buffer()` (`includes/class-optimizer.php:2405`) is
  registered at shutdown priority 1. It flushes (`ob_end_flush()`, so the
  minify callback runs) **only** when `buffer_level > 0` and
  `ob_get_level() === buffer_level`, then zeroes the level.
- `Page_Cache::flush_output_buffer()`
  (`includes/class-page-cache.php:661`) is registered at shutdown priority 5 so
  it runs after the optimizer. It flushes under the same level guard, resets
  `buffer_level = 0`, and deliberately leaves `buffer_started` set so
  `save_cache()` still knows the request was cacheable.

**Verification.** `tests/test-buffer-flush.php` executed via `wp eval-file`
(PASS) checks the level guard and ordering contract; source inspection confirms
the shutdown priorities.

---

## E — Log files inside the plugin directory (not reproducible)

The plugin writes its performance log to
`wp-content/uploads/wp-fastlayer-logs/performance.log`, created by
`Bootstrap::create_logs_directory()`, **not** inside the plugin folder. A legacy
`logs/` directory from an older layout is removed on upgrade with
`Bootstrap::delete_empty_directory()`. No log file is written under
`wp-content/plugins/fastlayer/`. **Verified by source inspection; no code change
needed.**

## F — Inline scripts/styles (not reproducible)

Inline additions use the WordPress APIs: `wp_add_inline_script()`,
`wp_print_inline_script_tag()` and `wp_add_inline_style()` in
`includes/class-admin.php` and `includes/class-optimizer.php`. Plugin Check
reports **0** `NonEnqueuedScript` / `NonEnqueuedStylesheet` findings.
**Verified by source inspection + Plugin Check; no code change needed.**

## G — Whole-plugin audit

Beyond A–D, the pass confirmed the plugin has no other ERROR-level Plugin Check
finding and that the WPCS per-sniff counts introduce no new rule category. The
remaining items are classified below.

## H — Toggle options read with a strict `'1' ===` compare (found at runtime)

**Finding.** Every feature toggle was read as `isset( $options['x'] ) && '1' === $options['x']`.
That is correct for the string `'1'` the settings form writes, but `update_option()`
also accepts an **integer** `1`, and WP-CLI/`wp option patch`/programmatic writes
produce one. With an integer stored, `'1' === 1` is `false`, so an enabled feature
(e.g. image delivery, ImageKit CDN, page cache) silently read as **disabled**.

**Root-cause fix.** The compare is now type-normalized at every reader:
`'1' === (string) $options['x']`. Touched readers:
`Webp::is_enabled()` / `is_image_delivery_enabled()`,
`Optimizer::is_css_enabled()` / `is_js_enabled()`,
`Page_Cache::is_cache_enabled()` / `is_preload_enabled()`,
`CDN::is_enabled()` and `Bootstrap::is_debug_enabled()`, plus the matching read in
`advanced-cache.php`.

**Verification.** `tests/test-option-type-strictness.php` executed via
`wp eval-file` — **PASS**. **A/B:** with `includes/` + `advanced-cache.php`
reverted to the committed bodies the script fails **15 checks**; restored, it
passes. Live end-to-end: setting `enable_image_delivery` to an integer `1` now
produces `<picture>`/WebP markup and setting `enable_imagekit_cdn` rewrites URLs.

## I — `FastLayer\CDN` was never instantiated (found at runtime)

**Finding.** `includes/class-cdn.php` defines the CDN/ImageKit hooks
(`wp_get_attachment_url`, `wp_calculate_image_srcset`,
`wp_get_attachment_image_attributes`, `the_content`, and the WooCommerce product
image hook), but `Bootstrap::load_modules()` never constructed the class. The
entire CDN/ImageKit feature was therefore **dead code**: enabling the option had
no effect on output.

**Root-cause fix.** Added `'CDN'` to the module list in
`Bootstrap::load_modules()` so the singleton is created and its hooks attach.

**Verification.** `tests/test-cdn-module-loaded.php` executed via `wp eval-file` —
**PASS** (asserts the instance exists and each front-end callback is attached).
**A/B:** pre-fix it fails **6 checks**; post-fix it passes. Live: the served HTML
after enabling ImageKit contains 5 rewritten `https://ik.imagekit.io/...?tr=...`
references with well-formed, escaped attributes.

## J — Multisite: one network-wide `config.json` for per-site options (found at runtime)

**Finding.** On a multisite network `fastlayer_options` is stored **per site**,
but the static config consumed by the drop-in was a **single file**
(`FASTLAYER_CACHE_DIR/config.json`, where `FASTLAYER_CACHE_DIR` is
`WP_CONTENT_DIR . '/cache/wp-fastlayer/'` — network-wide). The last site to save
its settings therefore determined the page-cache behaviour for **every** site on
the network.

**Evidence (scratch multisite, WP-CLI — reproduced during the fix).** Save
`exclude_urls=MAIN_ONLY` on the main site → the shared `config.json` shows
`MAIN_ONLY`; save `exclude_urls=SUB_ONLY` on the subsite → the per-site options
still hold `MAIN_ONLY` / `SUB_ONLY` respectively, but the single `config.json` now
shows `SUB_ONLY`. Any subsequent request for the main site used the subsite's
config. On the neutralised (pre-fix) build the new regression test reproduces
this as **7 failing checks** and only a shared `config.json` on disk.

**Root-cause fix.** The drop-in runs at `wp-settings.php` **before**
`ms-settings.php`, so it cannot call `get_current_blog_id()` or read the
database — the site must be derivable from the request alone. The design is
therefore **one JSON config file per site plus a pre-generated network index**
(JSON, never executable):

- `Bootstrap::write_static_config()` branches on `is_multisite_install()`.
  Single-site keeps `config.json` (and removes any stale `sites.json`/`sites/`).
  Multisite writes `sites/{blog_id}.json` for the current site and **additively**
  updates `sites.json` (`home URL → blog id`) so one site's save never clobbers
  another's entry, then deletes the shared `config.json` and legacy `config.php`.
- `Bootstrap::rebuild_multisite_index()` seeds the whole index from `get_sites()`
  (using `get_home_url()` so mapped domains are indexed as WordPress resolves
  them) on **network activation** and whenever the index is missing or malformed.
  `on_initialize_site()` (hooked to `wp_initialize_site`) adds a brand-new
  subsite immediately, and `on_home_option_updated()` (hooked to
  `update_option_home` / `update_option_siteurl`) re-indexes a site the moment its
  home or site URL changes.
- `advanced-cache.php` resolves the request with `fastlayer_resolve_blog_id()`
  (host lowercased + port stripped, then a whole-segment **longest home-path**
  match, mirroring `ms_load_current_site_and_network()`), loads
  `sites/{blog_id}.json`, and falls back to `config.json` only when there is no
  index. A request that matches no entry loads nothing (cache disabled —
  fail-safe). The legacy executable `config.php` is read only when there is no
  index and no JSON, so it can never answer for one site of a network.
- `Page_Cache::get_cache_files()` now returns only `.html` files, so a purge no
  longer deletes `config.json`/`sites.json`/`sites/*.json` (previously a purge on
  one site destroyed the network-wide config). `uninstall.php` deletes
  options/transients **per site** and removes the cache directory **recursively**
  (the nested `sites/` directory).

The full rationale, the compared alternatives (per-site only / network-level /
hybrid) and the migration + edge-case matrix are in
`docs/ADR-0001-multisite-config.md`.

**Hardening (round 10, same finding).** The first implementation only rebuilt
the index when the file was *missing*, so a site whose home URL changed, a
malformed `sites.json`, and a corrupt per-site file each left the site
unresolvable to the drop-in until the next settings save. Two additions close
that gap: `update_site_index()` now rebuilds a missing/malformed index from the
sites table (never truncating the network) and drops a site's stale
previous-home entry; `ensure_static_config()` now validates the index mapping and
the per-site JSON on `init` and rewrites when either is stale or invalid (this
also repairs corrupt `config.json` on single-site); and `on_home_option_updated()`
refreshes the index the moment `home`/`siteurl` changes. A healthy request still
performs no write (config/index mtimes verified stable across requests).

**Verification.** `tests/test-multisite-config.php` executed via `wp eval-file`:
**PASS** on a scratch **2-site network** (two subsites with deliberately
different `enable_cache` / `exclude_urls` stay isolated; the resolver matches
each home URL, a subsite child path, a subdomain, a mapped domain, a
port-suffixed host, a segment-boundary non-match and an unknown host; a changed
home URL is re-indexed and its old URL stops resolving to the site; a malformed
`sites.json` is rebuilt without dropping a site; an index entry lost for the
current site is restored on `init`) and **PASS** on the live single-site
(`config.json` authoritative, no multisite artifacts, a corrupt `config.json`
repaired on `init`). **A/B:** reverting the recovery logic makes the same test
**fail 7 checks**; reverting the whole finding to `HEAD` also fails.
`tests/test-config-json.php` stays green.

**Observation (same scratch run).** `wp core multisite-convert` rewrote
`wp-config.php` and dropped the FastLayer-owned `WP_CACHE` line (the site's own
`WP_CACHE_KEY_SALT` survived); re-activating the plugin restored it. The plugin
only writes `WP_CACHE` on activation, so a config rewrite by another tool is not
auto-corrected until the next activate. Not reproduced in a normal admin flow.

**Accepted limitation.** The cache directory is still shared, so a manual purge
on one site clears **all** sites' `.html` entries (cache keys are per
`scheme://host + REQUEST_URI`, so pages never collide). This is a performance
edge, not a correctness or privacy issue; recorded in the ADR.

## K — WPCS delta-gate allow-list never matched (tooling bug)

**Finding.** `tests/phpcs-gate.php` stores allow-listed **sniff** names
(`Generic.WhiteSpace.DisallowSpaceIndent`) but compares them against PHPCS
**message** sources (`Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed`). No
entry could ever equal a source, so the "formatting growth is expected and
ignored" contract was silently unimplemented and any single new space-indented
line failed the gate.

**Root-cause fix.** A source now matches an entry when it equals the entry **or**
starts with `entry . '.'`. The gate's strictness for real regressions is
unchanged.

**Verification.** Re-run against the fresh report → **PASS** (“no new WPCS
violations, 57 sniffs checked”). Two self-tests: a synthetic
`WordPress.Security.NonceVerification.Missing` still fails the gate (**exit 1**),
and a synthetic `Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed` passes
(**exit 0**, as documented).

---

## Round-9 runtime validation

Executed on the local WordPress 7.1.3 site (`http://fastlayer.local`, PHP 7.4.30,
nginx) and a disposable scratch install. Full commands and results are in
`docs/TEST-COVERAGE-GAPS.md §1`.

| Area | Result |
| --- | --- |
| HTTP smoke (`GET`/`HEAD`/404/search/feed/robots/login, `POST` login) | **PASS** — no PHP notices/warnings/fatals in the served HTML |
| Page cache serve/save/headers/invalidation | **PASS** (`X-Cache-Status: HIT`, `public, max-age=36000`; 404 not cached; edit purges) |
| Minification | **PASS** (comments removed, whitespace collapsed) |
| WebP generate + deliver | **PASS** (`.webp` siblings generated; `<picture>` delivered end-to-end) |
| CDN / ImageKit rewriting + escaping | **PASS** (after finding I) |
| Preload | **PARTIAL** — queue/batch/state verified and 1 page warmed; 3 URLs hit the plugin's 5 s per-URL cap because a **cold** render on this Local host takes 4.3–5.3 s |
| Fresh install + activate/deactivate/reactivate | **PASS** (owned `WP_CACHE` line + drop-in added/removed correctly) |
| Upgrade from 1.0.0 | **PASS** (option/cron migration) |
| Multisite | **PASS (WP-CLI level)** — 2-site network: per-site config isolated, index + resolver correct, no leakage (finding J fixed) |
| WooCommerce (network-activated) | **PASS** — no fatal, CDN product-image hook attached, product image renders, 9/9 scripts pass |
| PHPUnit | **PASS** — PHPUnit 9.6.38, 7 tests / 14 assertions, bootstrapped against a real `wp-load.php` |
| All 9 `tests/test-*.php` | **PASS** via `wp eval-file` |

---

### Retained findings (classified, not defects introduced here)

| Item | Level | Classification | Reason |
| --- | --- | --- | --- |
| `load_plugin_textdomainFound` (`wp-fastlayer.php:47`) | Warning | Intentional / documented | Retained for older-WordPress compatibility (`AGENTS.md §6`); removal needs maintainer confirmation. |
| `unexpected_markdown_file` (`AGENTS.md`) | Warning | Packaging | Dev-only file, excluded from the production ZIP (`RELEASE-CHECKLIST.md §5`). |
| `hidden_files` (`.gitignore`) | Warning | Packaging | Dev-only file, excluded from the production ZIP. Introduced with the git repository; not present in the round-8 scan that recorded “2 warnings”. |
| Legacy `include` fallback in `advanced-cache.php:38` | — | Accepted legacy read | The drop-in may read a pre-1.1.0 `config.php` **that the plugin itself wrote** until `ensure_static_config()` removes it. No new executable file is created; the canonical format is JSON. |
| `advanced-cache.php` native `parse_url()`/`readfile()`/`unlink()` | — | Intentional | Drop-in runs before WordPress defines those helpers. Each call carries a narrow, line-scoped `phpcs:ignore` with the reason. |

### Not run / blocked (honest status)

Round 9 executed most of what was previously listed as not run. What remains:

- WordPress **6.0** runtime (only 7.1.3 available locally; the static
  compatibility check passes).
- **Apache / LiteSpeed** cache serving (only **nginx** is installed here; nginx
  serves a direct `*.jpg` request before PHP, so the plugin's WebP negotiation for
  direct image URLs could not be observed).
- **PHP < 7.4 / 8.1 / 8.3** (only 7.4.30 and 8.2.29 available). The declared
  minimum stays 7.4; nothing below it is tested.
- A **full preload queue** (cold local renders 4.3–5.3 s vs the plugin's 5 s
  `PRELOAD_URL_TIMEOUT`).
- HTTP-level **multisite / WooCommerce** front-end flows (the scratch site has no
  web server; these were exercised at the WP-CLI level).
- First-class **PHPUnit** integration (no `composer`/`svn`); PHPUnit was run as a
  downloaded phar against a real `wp-load.php` bootstrap instead.

See `docs/TEST-COVERAGE-GAPS.md` for the full gap list.
