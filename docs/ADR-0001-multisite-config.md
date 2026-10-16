# ADR-0001 — Multisite static config: per-site files + a network index

- **Status:** Accepted (implemented, `1.1.0`)
- **Date:** 2026-10-15
- **Finding:** `docs/AUDIT-FINDINGS.md` → **J**
- **Affected files:** `includes/class-bootstrap.php`, `advanced-cache.php`,
  `includes/class-page-cache.php`, `uninstall.php`
- **Tests:** `tests/test-multisite-config.php`
- **Supersedes:** the single, network-wide `config.json` written by finding B.

---

## 1. Context

`fastlayer_options` is stored **per site** on a multisite network (each site has
its own `wp_{blog_id}_options` row). The static config that the
`advanced-cache.php` drop-in reads, however, lived in **one** file:

```
WP_CONTENT_DIR/cache/wp-fastlayer/config.json      // FASTLAYER_CACHE_DIR
```

`FASTLAYER_CACHE_DIR` (`wp-fastlayer.php`) is built from `WP_CONTENT_DIR`, which
is **shared by the whole network**. The consequence (finding J) is that the last
site to save its settings decided the page-cache behaviour for **every** site on
the network.

This is not merely a settings leak: the drop-in makes cache decisions
(`enable_cache`, `exclude_urls`, `exclude_cookies`, `cache_expiration`, …) from
that file before WordPress is loaded, so one site's exclusions or "cache
disabled" state silently applied to all sites.

### 1.1 The hard constraint: the drop-in runs before the site is known

WordPress loads a `WP_CACHE` drop-in from `wp-settings.php` **before** it is
possible to know which site of a network is being served:

| Step in `wp-settings.php` | What happens | Site known? |
| --- | --- | --- |
| `require WP_CONTENT_DIR/advanced-cache.php` | the drop-in runs | **No** |
| `require ABSPATH . WPINC . '/ms-settings.php'` | `ms_load_current_site_and_network()` sets `$current_blog`/`$current_site` | after this, yes |
| `is_multisite()` / `get_current_blog_id()` usable | `ms-settings.php` has run | yes |

So `get_current_blog_id()`, `get_option()` and the database are **not
available** inside the drop-in. Any design that needs the current site id there
must compute it from the request alone (`$_SERVER['HTTP_HOST']` +
`REQUEST_URI`).

## 2. Options considered

| # | Design | Pros | Cons | Verdict |
| --- | --- | --- | --- | --- |
| 1 | **Per-site config files, drop-in finds its own file** by replicating WordPress's site resolution from the request (host + path) | Correct per-site semantics; no DB needed | The drop-in has no sites table to compare against — it cannot know a network's home URLs without either a database query (unavailable) or a pre-computed list | Rejected **as stated**; the pre-computed list is the twist that makes a variant work (see #4) |
| 2 | **Network-level config only** — move settings to `site_option()` / network admin | One file, trivial for the drop-in | Changes the option model and public behaviour for every existing install; forces all subsites to share cache settings; needs a data migration and a UI move; breaks the existing per-site settings semantics | Rejected |
| 3 | **Hybrid: network defaults + per-site overrides** | Flexible | Needs inheritance/merge rules, a UI that distinguishes inherited from overridden values, and **still** needs the pre-bootstrap resolution problem solved; the most complex option with no requirement asking for it | Rejected (complexity not justified) |
| 4 | **Per-site config files + a pre-generated network index** | Correct per-site semantics; no DB in the drop-in; least code that actually solves the constraint | One extra generated file (`sites.json`) and one extra directory (`sites/`); the index must be kept fresh (new sites, mapped domains) | **Accepted** |

## 3. Decision

Adopt **option 4**: keep one config file per site and add a small network index
that the drop-in can read without WordPress.

```
WP_CONTENT_DIR/cache/wp-fastlayer/                 (FASTLAYER_CACHE_DIR)
├── config.json          ← single-site: the authoritative config (unchanged)
├── sites.json           ← multisite: { "https://home-url" : blog_id, … }
└── sites/
    ├── 1.json           ← multisite: site 1's full options array
    ├── 2.json           ← multisite: site 2's full options array
    └── …
```

- **Single-site:** only `config.json` exists. Any leftover `sites.json` /
  `sites/` (e.g. a network reduced to one site) is removed on the next write, so
  a stale index can never shadow `config.json`.
- **Multisite:** each site owns `sites/{blog_id}.json`; `sites.json` maps each
  site's **untrailingslashed home URL** to its id. The shared `config.json` is
  deleted on the first multisite write.
- Format is **JSON** in all cases — nothing under `wp-content/` is ever
  executable (finding B).

### 3.1 Drop-in resolution (no WordPress, no database)

`advanced-cache.php`:

1. If `sites.json` exists → load it and resolve the current site:
   - `fastlayer_normalize_host()` lowercases the request `Host` and strips the
     port (IPv6 brackets kept);
   - `fastlayer_request_path()` returns `REQUEST_URI` without query/fragment,
     always starting with `/`;
   - `fastlayer_resolve_blog_id()` compares the request host to each entry's
     home host, then matches the home **path** as a whole segment
     (`$path === $home_path` or `strpos( $path, $home_path . '/' ) === 0`,
     so home `/blog` does **not** match `/blogger/`), and returns the id with the
     **longest** matching home path. This mirrors
     `ms_load_current_site_and_network()`.
   - `fastlayer_load_site_options_from_index()` then reads
     `sites/{blog_id}.json` into `$fastlayer_options`.
2. If there is **no** index (single-site, or a multisite install that has not
   regenerated it yet) → read `config.json` as before.
3. Only when there is no index **and** no JSON options is the legacy executable
   `config.php` read (and never on a network that has an index: a legacy file is
   necessarily network-wide and must not answer for one site).

If the host/path matches no index entry, resolution returns `0`, no per-site file
is loaded, and the request falls through to "cache disabled" — fail-safe, never
serve one site's cache decisions for another.

### 3.2 Writers (`includes/class-bootstrap.php`)

- `write_static_config()` branches on `is_multisite_install()`:
  - single-site → `config.json` (adds `_blog_id = 1`), then removes any
    `sites.json`/`sites/` and legacy `config.php`;
  - multisite → `write_multisite_static_config()`.
- `write_multisite_static_config()` writes `sites/{blog_id}.json` (adding
  `_blog_id` and `_home_url`), then **additively** updates `sites.json` via
  `update_site_index()` (reads the index, sets one key, writes it back), and
  deletes the shared `config.json` + legacy `config.php`. One site's save never
  clobbers another's entry.
- `rebuild_multisite_index()` regenerates the whole index from `get_sites()` +
  `get_home_url()` (so mapped domains are indexed exactly as WordPress resolves
  them). Called on **network activation** and whenever the index is missing or
  malformed.
- `update_site_index()` reads the index, then: rebuilds it from the sites table
  when it is missing or malformed (so one site's write can never truncate the
  network to a partial index); drops a stale entry that still points at this
  site's **previous** home URL; keeps an existing entry whose host+path already
  matches (a scheme-only difference is left alone, because the drop-in ignores
  the scheme); and finally sets/overwrites the entry and writes it back.
- `ensure_static_config()` (on `init`) self-heals the **current** site: it
  rewrites the per-site file when that file is missing/unreadable or the index is
  missing/malformed, when the index no longer maps this site's current home URL
  (a lost entry or a changed home URL), or when a legacy `config.php` lingers.
- `on_home_option_updated()` (hooked to `update_option_home` /
  `update_option_siteurl`) re-writes the config the moment a site's home or site
  URL changes, so the index follows a Settings → General change, a network-admin
  address change, or a domain-mapping plugin with no delay. On single-site the
  same call refreshes `config.json`'s trusted-host list.
- `on_initialize_site()` (hooked to `wp_initialize_site`) adds a newly created
  site to the index immediately, so a brand-new subsite is resolvable before its
  first front-end hit.
- All writes go through `write_json_file()`, which checks the returned byte
  count **and** re-reads and compares the file, recording a failure via
  `record_write_failure()` otherwise. Every target is forced to stay inside
  `FASTLAYER_CACHE_DIR`.

### 3.3 Cache clearing and uninstall

- `Page_Cache::get_cache_files()` now returns **only `.html`** entries, so a
  purge no longer deletes `config.json`, `sites.json` or `sites/*.json`. Before
  this change a purge on one site destroyed the shared config for the whole
  network.
- `uninstall.php` deletes options/transients **per site** (iterating
  `get_sites()` with `switch_to_blog()`) and removes the cache directory
  **recursively**, so the nested `sites/` directory is emptied and the top-level
  directory can be removed.

## 4. Migration and edge cases

| Case | Behaviour |
| --- | --- |
| **Single-site upgrade** | None needed. `config.json` is unchanged; `ensure_static_config()` removes any stray multisite artifacts. |
| **Multisite upgrade with a shared `config.json`** | On the first request/activation the index is (re)built, each site's file is written on demand, and `config.json` is removed. Until the index exists the drop-in falls back to `config.json`, i.e. the previous (shared) behaviour — a safe, brief degradation, not an outage. |
| **Network activation** | `rebuild_multisite_index()` seeds every existing site's entry up front. |
| **New subsite** | `wp_initialize_site` → `on_initialize_site()` adds its index entry; its config file is written on the first `init` for that site. |
| **Existing subsite never visited** | Its entry is in the index (from the rebuild); the drop-in resolves it, finds no `sites/{id}.json`, and fails safe (cache disabled) until the site is first loaded and the file is written. |
| **Blog switching (`switch_to_blog()`)** | Writes use `get_current_blog_id()` inside the switched context, so the correct `sites/{id}.json` is updated. |
| **Mapped domain** | Indexed with the site's real home URL (via `get_home_url()`), so the drop-in's host match resolves it. |
| **Missing / unreadable / partial config** | The drop-in uses `config.json`/`sites/*.json` only if they parse to an array; otherwise options stay empty and caching is disabled for that request (fail-safe). On the next `init`, `ensure_static_config()` repairs a missing or malformed `config.json`/`sites/{id}.json` and `update_site_index()` rebuilds a missing or malformed `sites.json` from the sites table, so a bad file disables caching for one request rather than indefinitely. |
| **`wp-config.php` rewrite drops `WP_CACHE`** | Unrelated to J; observed only with `wp core multisite-convert` (see `AUDIT-FINDINGS.md` J note). Re-activating restores the owned constant. |
| **Cache invalidation** | `.html` entries are purged as before; config/index/site files are intentionally preserved so a purge does not lose other sites' identity. |

## 5. Consequences and accepted limitations

- **Correctness:** each site's cache decisions come from that site's own
  settings. Two subsites with deliberately different `enable_cache` /
  `exclude_urls` values no longer affect each other (proved by
  `tests/test-multisite-config.php`).
- **Extra generated files:** `sites.json` + `sites/`. Both are JSON, protected
  by the same directory protection as the rest of the cache directory, and
  removed by uninstall.
- **Shared HTML cache directory (accepted, performance only):** cache keys are
  `md5( scheme://host + REQUEST_URI + variant )`, so pages never collide across
  sites, but a manual purge on one site deletes **all** sites' `.html` entries
  because they share one directory. This is a performance edge, not a
  correctness or privacy issue; a per-site cache subdirectory is a possible
  future change if it ever matters.
- **Index freshness:** the index is maintained on network activation, on
  `wp_initialize_site`, on every `update_option_home` / `update_option_siteurl`,
  and on `init` (self-heal). A home URL change made through the options API is
  reflected immediately; a change made behind the plugin's back (for example
  direct SQL that bypasses `update_option()`) is recovered on the site's next
  `init`, which detects that the index no longer maps its current home URL and
  rewrites it.
- **Subdomain vs subdirectory:** the resolution algorithm handles both (host
  match first, then longest path). The local audit exercised a **subdirectory**
  network and additionally unit-tests subdomain, mapped-domain, port-stripping,
  segment-boundary and no-match cases against a synthetic index.

## 6. Verification

| Evidence | Result |
| --- | --- |
| `tests/test-multisite-config.php` on a scratch **multisite** (2 sites) | **PASS** — per-site files isolated, index correct, resolver matches each home URL + child paths, home-URL change re-indexed + old entry dropped, malformed index rebuilt, lost entry restored, corrupt per-site file repaired |
| Same test, A/B (the new recovery logic reverted) | **FAIL — 7 checks** (home-URL change not indexed, stale entry kept, malformed index truncated, lost entry not restored, corrupt file not repaired) |
| Full J A/B (`git show HEAD:` for the J files) | **FAIL — 7 checks** (reproduces finding J end-to-end) |
| Same test on the live **single-site** | **PASS** — `config.json` authoritative, no multisite artifacts, corrupt `config.json` repaired on `init` |
| `tests/test-config-json.php` | **PASS** — `config.json` round-trips, legacy `config.php` removed |
| Plugin Check (canonical exclusions) | **0 ERROR / 3 WARNING** (unchanged) |
| WPCS per-sniff delta gate | **PASS** (57 sniffs, no new rule category) |

Commands and full output are recorded in `docs/TEST-COVERAGE-GAPS.md` §1.8.
