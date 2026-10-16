# ARCHITECTURE.md — FastLayer

Technical overview of how the FastLayer plugin is structured and how it works.
Everything below reflects the `1.1.0` source in this repository.

---

## 1. Bootstrap sequence

### 1.1 `wp-fastlayer.php` (main file)

1. Guards against direct access (`ABSPATH`).
2. Defines constants:
   - `FASTLAYER_VERSION` = `1.1.0`
   - `FASTLAYER_FILE`, `FASTLAYER_PATH`, `FASTLAYER_URL`
   - `FASTLAYER_CACHE_DIR` = `WP_CONTENT_DIR . '/cache/wp-fastlayer/'`
3. Registers a shutdown function that logs **fatal** errors to the PHP error log.
4. Defines `fastlayer_debug_log()` (only logs when `WP_DEBUG` is on).
5. Calls `load_plugin_textdomain( 'fastlayer', ... )` (legacy; see REVIEW-FIXES B17).
6. Registers a PSR-0-ish autoloader: `FastLayer\Foo_Bar` → `includes/class-foo-bar.php`.
7. Requires `includes/class-bootstrap.php` directly (so `Bootstrap` exists even if
   the autoloader does not fire), then instantiates `FastLayer\Bootstrap`.
8. Instantiates `FastLayer\Admin` (also loaded by `Bootstrap`).

### 1.2 `includes/class-bootstrap.php` (lifecycle)

- Singleton via `get_instance()`.
- Constructor hooks:
  - `init` → `init()`
  - `register_activation_hook` → `activate()`
  - `register_deactivation_hook` → `deactivate()`
  - `update_option_fastlayer_options` / `add_option_fastlayer_options` →
    `sync_static_config()` (regenerates `config.json`; on multisite writes the
    site's `sites/{blog_id}.json` + `sites.json`)
  - `wp_initialize_site` → `on_initialize_site()` (adds a new subsite to the
    network index)
  - legacy `wp_fastlayer_options` add/update → `mirror_legacy_options()`
  - `admin_notices` (admin only) → `admin_notices()`
- `init()` runs, guarded by `try/catch`:
  1. `create_cache_directory()` — `wp_mkdir_p()` + directory protection.
  2. `create_logs_directory()` — creates `uploads/wp-fastlayer-logs/`, removes the
     legacy in-plugin `logs/` directory.
  3. `migrate_legacy_state()` — one-time rename migration.
  4. `reset_options_if_needed()` — normalises/creates the options row.
  5. `ensure_static_config()` — (re)writes `config.json` (single-site) or the
     per-site file + `sites.json` index (multisite); removes legacy
     `config.php`; rebuilds a missing multisite index.
  6. `load_modules()` — instantiates `Page_Cache`, `Optimizer`,
     `Database_Optimization`, `Admin_Bar`, `Webp`, `CDN`, then `Admin`.
- `activate()` additionally: sets defaults, schedules the hourly preload cron,
  installs the `advanced-cache.php` drop-in and adds the `WP_CACHE` constant.
- `deactivate()` clears cron (current + legacy), removes the drop-in (only if
  owned) and removes the `WP_CACHE` constant (only if owned).

### 1.3 `includes/class-admin.php`

- Settings screen, menu slug `wp-fastlayer`, option name `fastlayer_options`.
- Handles `admin_post_*` and `wp_ajax_*` actions (settings save, cache purge,
  ImageKit test, WebP bulk, debug reports, DB cleanup).
- Constructor is also invoked defensively from `Bootstrap::load_modules()`.

> **Note:** `includes/class-cdn.php` is instantiated by
> `Bootstrap::load_modules()` so its front-end hooks are attached (finding I).

---

## 2. Class map

| Class | File | Responsibility |
| --- | --- | --- |
| `FastLayer\Bootstrap` | `includes/class-bootstrap.php` | Lifecycle, drop-in install, `wp-config.php` + `WP_CACHE`, static config, admin notices, logging, legacy migration. |
| `FastLayer\Page_Cache` | `includes/class-page-cache.php` | Serving/saving cached pages, cache key, skip rules, preload batches, invalidation. |
| `FastLayer\Optimizer` | `includes/class-optimizer.php` | HTML/CSS/JS minify, combine, defer/async, smart delay, critical CSS, lazy load, LQIP enqueue. |
| `FastLayer\Webp` | `includes/class-webp.php` | WebP generation and serving, `.htaccess` rewrite rules, LQIP generation, bulk jobs. |
| `FastLayer\CDN` | `includes/class-cdn.php` | CDN / ImageKit URL rewriting, LCP preload, endpoint validation. |
| `FastLayer\Admin` | `includes/class-admin.php` | Settings UI + all admin-post/ajax handlers. |
| `FastLayer\Admin_Bar` | `includes/class-admin-bar.php` | Admin-bar cache actions (nonce `fastlayer_admin_bar_nonce`). |
| `FastLayer\Database_Optimization` | `includes/class-database-optimization.php` | DB cleanup routines, locks, notices. |

---

## 3. The `advanced-cache.php` drop-in

FastLayer has **two** cache code paths that must stay in sync:

1. **The drop-in** (`advanced-cache.php` in the plugin root, copied to
   `wp-content/advanced-cache.php`). It runs *before* WordPress is fully loaded.
2. **`Page_Cache`** (loaded inside WordPress) which *writes* cache files and
   *invalidates* them.

### 3.1 Drop-in flow

1. WordPress defines `ABSPATH` and loads `wp-content/advanced-cache.php` very
   early (because `WP_CACHE` is `true`).
2. The drop-in loads its config into `$options`:
   - **multisite** — if `wp-content/cache/wp-fastlayer/sites.json` exists, resolve
     the current request (host + longest home-path match, mirroring
     `ms_load_current_site_and_network()`) to a blog id and load
     `sites/{blog_id}.json`; a request that matches no entry loads nothing
     (cache disabled — fail-safe);
   - **single-site / no index** — load `config.json` (plain JSON);
   - a legacy executable `config.php` is only read when there is no index and no
     JSON (see `docs/ADR-0001-multisite-config.md`).
3. `fastlayer_cache_enabled()` checks `enable_cache === '1'`.
4. `fastlayer_should_skip_cache()` returns true (do not serve cache) when:
   - the request `Host` is not in the trusted host list (`_home_host`,
     `WP_HOME`, `WP_SITEURL`);
   - the `X-FastLayer-Preload` header is present (preload sitemap fetches);
   - a `wordpress_logged_in_*` cookie exists, or `is_user_logged_in()`;
   - method is `POST`/`HEAD`, or `DOING_AJAX` / `DOING_CRON` / `XMLRPC_REQUEST` /
     `WP_ADMIN` / `REST_REQUEST` (`rest_route=` also);
   - preview or search (`preview*`, `s`);
   - the path is in the built-in skip list (`/wp-admin/`, `/wp-login.php`,
     `/wp-json/`, `/cart/`, `/checkout/`, `/my-account/`, `/feed/`, …);
   - it matches `exclude_urls`, `exclude_cookies`, or `exclude_user_agents`.
5. Cache key: `md5( scheme://host + REQUEST_URI + '|' + accept-variant )`, where
   the accept-variant is `accept-avif`, `accept-webp`, or `accept-legacy`.
6. If the file exists and is inside the cache dir (`realpath` check) and not
   expired, the drop-in sends `Vary: Accept`, `X-Cache-Status: HIT`, a
   `Cache-Control` header, then `readfile()` and `exit`.

> The drop-in uses **native** `parse_url()`, unprefixed globals, and `readfile()`
> because WordPress functions are not available at this stage. Plugin Check
> flags these; they are intentional (REVIEW-FIXES B11).

### 3.2 `WP_CACHE` detection and the recurring warning

- WordPress only loads `advanced-cache.php` when `WP_CACHE` is truthy in
  `wp-config.php` **before** `wp-settings.php` runs.
- `Bootstrap::add_wp_cache_constant()` adds
  `define( 'WP_CACHE', true ); // wp-fastlayer:owned-wp-cache` above the
  "stop editing" marker, but only if no `WP_CACHE` define already exists.
- `Bootstrap::admin_notices()` warns if the drop-in exists but `WP_CACHE` is
  falsy/undefined. See REVIEW-FIXES **A9** for why this warning can reappear
  after deactivate → reactivate (config not writable, or `defined()` evaluated
  before the edit takes effect in that request).

---

## 4. Options, config, cron, transients

### 4.1 Options

| Option | Purpose |
| --- | --- |
| `fastlayer_options` | Main settings array (source of truth). |
| `wp_fastlayer_options` | Legacy mirror (pre-1.1.0). Kept in sync, not deleted. |
| `fastlayer_webp_last_run` | WebP bulk bookkeeping. |
| `fastlayer_image_runtime_debug_samples` | Runtime image debug samples. |
| `fastlayer_preload_state` | Preload progress state. |
| `fastlayer_preload_lock` | Preload mutex. |
| `fastlayer_preload_batch_lock` | Batch preload mutex. |

Defaults are defined in `Bootstrap::get_default_options()` (cache expiry,
minify/combine/defer toggles, WebP/LQIP quality, ImageKit settings, DB cleanup
toggles, exclusions, `debug_mode`, `_version`, …).

### 4.2 Static config files

`Bootstrap::write_static_config()` generates the pre-WordPress config consumed by
the drop-in. The format is **JSON** (never executable PHP):

```
wp-content/cache/wp-fastlayer/
├── config.json      # single-site: the authoritative mirror of the options row
├── sites.json       # multisite: { "https://home-url" : blog_id, … }
└── sites/
    └── {blog_id}.json   # multisite: that site's full options array
```

- **Single-site:** only `config.json`. A stale `sites.json` / `sites/` is removed
  on the next write so it cannot shadow `config.json`.
- **Multisite:** `fastlayer_options` are stored per site, but the cache
  directory is network-wide, so each site keeps its own `sites/{blog_id}.json`
  and `sites.json` maps each site's home URL to its id. The drop-in (which runs
  before WordPress can know the current site) resolves the request against that
  index. `config.json` is deleted on the first multisite write.
- The write is byte-count checked and read-back verified; failures go to the
  `fastlayer_write_failures` transient and are shown to admins.
- `_home_host` (trusted hosts) is injected only here, never stored back in the DB
  row. Multisite files additionally carry `_blog_id` and `_home_url`.
- The index is (re)built on network activation, when it is found missing on
  `init`, and on `wp_initialize_site` for a brand-new subsite.

Full rationale, the alternatives considered and the migration/edge-case matrix
live in `docs/ADR-0001-multisite-config.md`.

### 4.3 Cron

| Hook | Schedule | Handler |
| --- | --- | --- |
| `fastlayer_preload_cache` | hourly | `Page_Cache` preload |
| `fastlayer_preload_batch` | single events | `Page_Cache` batch |
| `wp_fastlayer_preload_cache` | legacy | migrated/cleared |
| `wp_fastlayer_preload_batch` | legacy | cleared |

### 4.4 Transients

`fastlayer_write_failures` (300 s), `fastlayer_cache_debug_trace` (300 s),
`fastlayer_cache_debug_test_result` (300 s), `fastlayer_imagekit_test_notice`,
`fastlayer_dismissed_nginx_webp_notice`, `fastlayer_image_debug_report_payload`
(1800 s), `fastlayer_lcp_last_report` (12 h),
`fastlayer_db_cleanup_notice_<user_id>` (120 s), plus WebP batch/summary
transients.

### 4.5 Legacy migration (`Bootstrap::migrate_legacy_state`)

- If `fastlayer_options` does not exist and `wp_fastlayer_options` is non-empty,
  copy it across (once).
- Move a scheduled `wp_fastlayer_preload_cache` to `fastlayer_preload_cache`.
- Clear `wp_fastlayer_preload_batch`.
- `mirror_legacy_options()` keeps the rows aligned until the next settings save.

---

## 5. Cache invalidation

`Page_Cache` registers hooks so edits invalidate the matching cache entries
(post save/update, comments, term/menu/widget changes, settings save, manual
purge). The admin bar and admin screens offer manual purge actions guarded by
`fastlayer_admin_bar_nonce` / admin nonces.

---

## 6. Optimization pipeline

`Optimizer` hooks `wp_enqueue_scripts` (and related filters) at high priority:

- **HTML:** remove comments / whitespace (Gutenberg comments preserved by
  default).
- **CSS:** minify, combine, remove comments/unused, inline critical CSS, Google
  Fonts `display`, Gutenberg CSS optimization. Combined files are registered
  with `wp_register_style()` and enqueued.
- **JS:** minify, combine, defer, async, "safe mode", smart delay (loader
  registered with `wp_register_script()`). Combined files registered with
  `wp_register_script()`.
- **Lazy load / LQIP:** `assets/js/lazyload.js`, `assets/js/lqip-frontend.js`
  enqueued on the front end.
- `set_error_handler( '__return_false' )` is used around minification to suppress
  libxml/PHP warnings (intentional; flagged by Plugin Check).

### Generated asset locations

| Artifact | Location |
| --- | --- |
| Combined/minified CSS & JS | `wp-content/uploads/wp-fastlayer-min/` |
| Page cache HTML + `config.json`/`sites.json`/`sites/{id}.json` + `.htaccess` + `index.php` | `wp-content/cache/wp-fastlayer/` |
| Preload artifacts | `wp-content/uploads/wp-fastlayer-preload/` |
| Debug log | `wp-content/uploads/wp-fastlayer-logs/performance.log` |
| Drop-in | `wp-content/advanced-cache.php` |

### Write safety

There is no WP-Filesystem abstraction for these writes; instead the plugin:
checks/reserves the directory, forces paths inside approved directories, checks
byte counts, re-reads and compares, and records failures via
`Bootstrap::record_write_failure()` (surfaced as an admin notice).

---

## 7. WebP

- `Webp` generates `.webp` next to originals (bulk and on-demand) and records
  `fastlayer_webp_last_run`.
- Apache/LiteSpeed wrapping: rules are inserted into the site-root `.htaccess`
  inside a `# BEGIN WP FastLayer WebP` … `# END WP FastLayer WebP` block
  (`Webp::HTACCESS_MARKER`) using `insert_with_markers`-style markers. Removal
  is always marker-scoped, so a foreign plugin's block or a hand-written rule is
  never touched.
- FastLayer's directives are cleared when WebP is switched off (the init-time
  self-heal), on **deactivation**
  (`Bootstrap::deactivate()` → `Webp::remove_rewrite_rules_on_deactivate()`,
  best-effort and guarded so it can never fatal), and on **uninstall**
  (`uninstall.php`, guarded on marker presence and writability). Because
  WordPress core's `insert_with_markers()` always rewrites the `# BEGIN`/`# END`
  markers and merges in its informational comments, the block itself is not
  deleted — it is emptied of directives, which is what stops the rewrites.
  Generated `.webp` files are deliberately left in place: their ownership cannot
  be established reliably, and they are inert once the rewrite rule is gone.
- A reactive PHP serving path responds with `Content-Type: image/webp`,
  `Vary: Accept`, `ETag`, `Last-Modified`, and `304` on conditional requests.
- On nginx, `.htaccess` is ignored, so an admin notice explains WebP needs a
  server-level rule (dismissible via `fastlayer_dismissed_nginx_webp_notice`).
- Bulk generation sets `set_time_limit(0)` for long batches.

---

## 8. CDN / ImageKit

- `CDN` rewrites internal asset URLs to the configured CNAME, and rewrites Media
  Library image URLs to the ImageKit endpoint with a `tr=` transformation string
  (auto format/quality + display width).
- ImageKit is **off by default**; nothing is sent until enabled *and* an endpoint
  is entered.
- Endpoint handling: restricts to `imagekit.io` and subdomains, rejects IPs and
  embedded credentials, prepends `https://` when no scheme is given, and uses
  `http://` verbatim only if explicitly entered.
- Only the endpoint is stored (in options and in the generated JSON config); no
  API keys.
- LCP preload emits a `<link rel="preload">` for the hero image (optionally the
  CDN/ImageKit URL).

---

## 9. Database optimization

- `Database_Optimization` runs configurable cleanups: revisions, trashed posts,
  spam comments, auto-drafts, transients (all/expired), duplicate/orphaned
  meta (`post`/`comment`/`user`/`term`), oEmbed cache.
- Concurrent runs are prevented by locks (`fastlayer_db_cleanup_lock_*`).
- Results are reported with a per-user notice transient
  (`fastlayer_db_cleanup_notice_<user_id>`).
- All actions are capability- and nonce-gated in `Admin`.

---

## 10. Uninstall

`uninstall.php` runs on plugin deletion (guarded by `WP_UNINSTALL_PLUGIN`) and
removes: current + legacy options, current + legacy transients, current + legacy
cron, cache/min/preload/log directories (and their protection files), the
drop-in (only if owned), and the `WP_CACHE` constant (only if owned). It never
touches a foreign drop-in or foreign constant.

---

## 11. Known architectural risks / open questions

- **Two cache implementations** (drop-in functions + `Page_Cache`) must agree on
  skip rules and URL normalisation; divergence is a correctness risk.
- **Multisite config resolution** is split across two layers: `Bootstrap` writes
  the per-site files + `sites.json` index, and `advanced-cache.php` resolves the
  current site from that index. They must agree on the home-URL normalisation
  (untrailingslashed home URL; host lowercased with the port stripped; whole-
  segment path match, longest prefix wins). See
  `docs/ADR-0001-multisite-config.md`.
- **Unprefixed globals** in `advanced-cache.php` / `uninstall.php` remain
  (REVIEW-FIXES A1). The `$_wpfl_bootstrap_file` bootstrap variable that used to
  live in `wp-fastlayer.php` was **removed in round 4** (2026-10-12); the
  bootstrap path is now inlined in the `file_exists()`/`require_once()` calls.
- **Direct filesystem writes** bypass the WP Filesystem API (REVIEW-FIXES A4/B11).
- **Direct DB queries** in DB optimization need `prepare()`/caching review
  (REVIEW-FIXES B3/B6/B7).
- **`WP_CACHE` re-warning** after reactivate (REVIEW-FIXES A9).
- No PHPUnit harness is committed; helpers are covered by `tests/test-*.php` run
  with `wp eval-file` (see `docs/TEST-COVERAGE-GAPS.md`). Multisite is verified at
  the WP-CLI level on a scratch network; HTTP-level multisite flows are not
  exercised here.
