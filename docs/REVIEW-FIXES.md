# REVIEW-FIXES.md — WordPress.org Review Issue Tracker

Tracks each issue raised (or implied) by the WordPress.org plugin review, the
current state of the fix in the code, and the evidence behind each status.

---

## Provenance and limitations

**Read this before trusting any status below.**

- The original WordPress.org review email/message could **not** be located. It is
  not in the plugin folder, the site's wp-content, the local `Downloads` folder,
  nor the project archive (`FastLayer.zip` on the Desktop). See
  `docs/ARCHITECTURE.md` for the file inventory that was checked.
- The issue list below is therefore **reconstructed** from three sources:
  1. The `1.1.0` changelog in `readme.txt` (which describes what was fixed in
     response to review feedback).
  2. The Plugin Check (PCP) export
     `fastlayer-wp-fastlayer-php-20261007-050544.csv` (275 findings).
  3. Direct source inspection of the current `1.1.0` code.
- The PCP export was generated against a **slightly older/dev build**:
  it references `$_POST['wp_fastlayer_options']` and line numbers that do not all
  match the current files. PCP line numbers are treated as **approximate** and
  have been re-checked against the current source. Where the current code no
  longer matches a finding, it is marked **stale**.
- At the time rounds 1–2 were written, **no PHP runtime was available** in this
  environment, so those statuses mean *the fix is present and correct by source
  inspection*. From **round 3 (2026-10-13)** the LocalWP **PHP 7.4.30 / 8.2.29**,
  **WP-CLI 2.12.0** and **Plugin Check 2.1.0** toolchain became available, so the
  rounds 3–7 results are **actually executed** (see Part D and
  Part B-implementation-3 … -7). `composer` and PHPUnit are still unavailable.
  Manual compatibility testing on **PHP 7.4.30** was also completed outside this
  workspace (maintainer-reported, 2026-10-12); see "PHP version requirement
  (reviewer feedback)" below. Runtime behaviour is tracked in
  `tests/QA-CHECKLIST.md`.
- Automated tests that **were** executed: `node --check` on all four
  `assets/js/*.js` files — **all pass** (node v22.23.2); plus, from round 3, the
  PHP lint / WPCS / Plugin Check / `wp eval-file` runs listed in Part D.

### Status legend

| Status | Meaning |
| --- | --- |
| **Verified Fixed** | The fix is present in the current source and behaves as intended by inspection. |
| **Partially Verified** | The fix exists but is incomplete, or only part of the issue is addressed. |
| **Not Fixed** | The issue is still present in the current source. |
| **Not Yet Tested** | No verification was performed (e.g. needs a runtime/live site). |

---

## PHP version requirement (reviewer feedback)

**Finding: no located WordPress.org review feedback explicitly requires testing
on PHP versions below 7.4.** The original review message could not be found (see
Provenance above), and no document in this repository asks for a lower PHP
target. The instruction was not to assume such a requirement without explicit
review feedback, and none was found to quote.

| Item | Value |
| --- | --- |
| Declared plugin minimum (`Requires PHP`) | **`7.4`** — **unchanged** |
| Lowest PHP version **actually tested** | **PHP 7.4.30** (manual compatibility testing completed, maintainer-reported 2026-10-12) |
| PHP versions **below 7.4** | **Not tested** — not available in the testing environment; no reviewer requirement found |
| PHP 8.1 / 8.2 / 8.3 | **Not tested** (see `tests/QA-CHECKLIST.md` O-02) |
| PHP lint, PHPCS, PHPUnit, Plugin Check | **Not run / Blocked** — no PHP, Composer or WP-CLI in the reference environment |

No workaround is proposed or implied, and **no claim is made that any PHP
version below 7.4 was tested.**

---

## Part A — Review issues (from the 1.1.0 changelog + code inspection)

### A1. Plugin identifiers not using the required `fastlayer` prefix

- **Original issue:** Options, cron hooks, transients, asset handles and classes
  used the wrong prefix, which the directory guidelines reject.
- **Files:** `includes/class-bootstrap.php`, `includes/class-page-cache.php`,
  `includes/class-admin.php`, `wp-fastlayer.php`, `uninstall.php`.
- **Current implementation:** Options/cron/transients/classes/handles renamed to
  `fastlayer_*` (e.g. `fastlayer_options`, `fastlayer_preload_cache`,
  `fastlayer_preload_batch`, `fastlayer_write_failures`).
- **Status:** **Partially Verified**
- **Evidence (source inspection):** New names are used throughout. Unprefixed
  globals remain in `advanced-cache.php` (`$cache_dir`, `$config_file`,
  `$options`, `$cache_expiration`) and in `uninstall.php` (all intentional; those
  files run before/outside the full bootstrap). The `$_wpfl_bootstrap_file`
  variable that used to live in `wp-fastlayer.php` was **removed in round 4**
  (2026-10-12): the bootstrap path is now inlined exactly as before, so no new
  global is introduced — see `Part B-implementation-4` (R4-2). Plugin Check now
  reports **32 × `PrefixAllGlobals.NonPrefixedVariableFound`** (down from 33).
  See Appendix rule PCP-05.

### A2. No migration path for existing installs after the rename

- **Original issue:** Renaming identifiers would orphan existing settings,
  schedules and cache ownership.
- **Files:** `includes/class-bootstrap.php`.
- **Current implementation:** `migrate_legacy_state()` copies
  `wp_fastlayer_options` → `fastlayer_options` once and re-schedules the legacy
  cron under the new name; `mirror_legacy_options()` hooks keep the two option
  rows in sync; `is_our_advanced_cache_dropin()` recognises legacy
  `wp_fastlayer_*` function names; `uninstall.php` deletes both naming rows.
- **Status:** **Verified Fixed**
- **Evidence (source inspection):** `class-bootstrap.php:170-200`, `:430-449`;
  `uninstall.php:7-18,43-48,99-112`.

### A3. Combined CSS/JS not registered through WordPress core APIs

- **Original issue:** Combined/optimized assets were printed directly instead of
  being registered/enqueued, which Plugin Check flags as non-enqueued
  script/style (`NonEnqueuedScript` / `NonEnqueuedStylesheet`).
- **Files:** `includes/class-optimizer.php`.
- **Current implementation:** Combined assets now go through
  `wp_register_style()` / `wp_register_script()` and are enqueued
  (`class-optimizer.php:966-978`, `:1032-1042`); smart-delay loader uses
  `wp_register_script()` / `wp_enqueue_script()` (`:875-876`); critical CSS and
  lazy-load use the enqueue APIs (`:2067-2068`, `:2516`).
- **Status:** **Partially Verified**
- **Evidence (source inspection + PCP):** The enqueue APIs are used. The PCP
  export still contains one `NonEnqueuedScript` and one `NonEnqueuedStylesheet`
  finding; these may be **stale** or point at a remaining direct print. Exact
  location needs re-running Plugin Check against the current build (Blocked:
  no PHP runtime).

### A4. Unsafe / unverified writes to cache and minified-asset files

- **Original issue:** File writes could fail partially or write outside the
  intended directory.
- **Files:** `includes/class-bootstrap.php`, `includes/class-optimizer.php`,
  `includes/class-webp.php`.
- **Current implementation:** `write_static_config()` forces the target inside
  the cache directory, checks the byte count, re-reads and compares the content,
  and records failures via `record_write_failure()`; failures surface as an admin
  notice. Writes use `LOCK_EX`.
- **Status:** **Verified Fixed**
- **Evidence (source inspection):** `class-bootstrap.php:336-373`, `:54-64`.

### A5. `wp-config.php` could be blanked by a failed read

- **Original issue:** Passing a `false` read into `preg_replace()` yielded an
  empty string that could overwrite `wp-config.php`.
- **Files:** `includes/class-bootstrap.php`.
- **Current implementation:** `read_wp_config()` rejects non-files, unreadable
  files, write-only files, empty content, and content that does not start with
  `<?php`; the write goes through a backup + staged file + `rename()` with a
  read-back comparison.
- **Status:** **Verified Fixed**
- **Evidence (source inspection):** `class-bootstrap.php:488-516`, `:533-607`.

### A6. Foreign `advanced-cache.php` drop-in could be overwritten/removed

- **Original issue:** Installing/removing the drop-in could clobber another
  caching plugin's drop-in.
- **Files:** `includes/class-bootstrap.php`, `uninstall.php`.
- **Current implementation:** Ownership is proven by bundled-file hash, the
  `wp-fastlayer:advanced-cache-dropin` marker, or FastLayer function names
  (including legacy). Anything else is left untouched.
- **Status:** **Verified Fixed**
- **Evidence (source inspection):** `class-bootstrap.php:430-473`;
  `uninstall.php:93-112`.

### A7. The wrong `WP_CACHE` define could be removed

- **Original issue:** Deactivation/uninstall could remove a `WP_CACHE` constant
  it did not create.
- **Files:** `includes/class-bootstrap.php`, `uninstall.php`.
- **Current implementation:** Addition refuses to touch an existing `WP_CACHE`
  define; removal requires the exact owner marker
  `wp-fastlayer:owned-wp-cache` on the same line. Pre-marker installs keep their
  constant (safe).
- **Status:** **Verified Fixed**
- **Evidence (source inspection):** `class-bootstrap.php:609-680`.

### A8. Uninstall did not remove all plugin data

- **Original issue:** Options, transients, cron jobs and generated files were
  left behind on delete.
- **Files:** `uninstall.php`.
- **Current implementation:** Removes current + legacy options, current + legacy
  transients, current + legacy cron, the cache dir, min dir, preload dir, log
  dir, the drop-in (if owned) and the `WP_CACHE` constant (if owned).
- **Status:** **Verified Fixed**
- **Evidence (source inspection):** `uninstall.php:1-148`.

### A9. `WP_CACHE` warning recurs after deactivate → reactivate

- **Original issue / symptom:** After deactivating and reactivating, the admin
  warning *"The page cache drop-in is installed but `WP_CACHE` is not set to
  `true` in `wp-config.php`"* is shown even though the constant was previously
  added.
- **Files:** `includes/class-bootstrap.php`.
- **Current implementation:** `admin_notices()` (`:85-90`) warns whenever
  `! defined('WP_CACHE') || ! WP_CACHE` **and** the drop-in exists.
  `add_wp_cache_constant()` (`:609-642`) returns early if `WP_CACHE` is already
  defined, and skips if a `WP_CACHE` `define(...)` already exists in the file.
  Deactivation removes the constant only if it carries FastLayer's marker.
- **Likely cause(s) (not confirmed):**
  1. `wp-config.php` is not writable, so the constant is never added and the
     warning appears on every admin load.
  2. During the activation request, `WP_CACHE` is not yet defined in the running
     process, so the notice evaluates against the pre-edit state; on the *next*
     request the constant may be present. This is a timing/caching nuance around
     `defined()` (some opcode caches/object caches keep the old config).
- **Status:** **Not Fixed** (normal path **verified** in round 4; non-writable
  path still open)
- **Evidence:** Source inspection (logic above) plus the round-4 live
  deactivate/reactivate cycle: the drop-in and the FastLayer-owned `WP_CACHE`
  line are cleanly removed on deactivate and restored on reactivate with **no
  duplicate**, `wp-config.php` still lints clean, and `defined('WP_CACHE')` is
  `true` on the next request. The reported recurring warning therefore does **not**
  reproduce on a normally writable site. The remaining **not-tested** case is a
  **non-writable** `wp-config.php`, where the constant cannot be written and the
  warning is expected on every load. Tracking test: `tests/QA-CHECKLIST.md` §C
  ("Activation & WP_CACHE"), C-03. See Part B-implementation-4.

---

## Part B — Plugin Check (PCP) findings still open

The export contains **275 findings** (171 WARNING, 104 ERROR). These are the
larger review-facing groups. Full rule-by-rule appendix is in Part C.

> **Current status (2026-10-13):** after the round-7 AlternativeFunctions /
> WP_Filesystem code pass the live Plugin Check result is **0 ERROR / 160
> WARNING** — the 16 `AlternativeFunctions` ERRORs are cleared. The **275-finding**
> figure above is the *2026-10-07 baseline export* this reconstruction started
> from. See Part B-implementation-7 for the resolution, Part C for the baseline
> appendix, and Part D for the executed results.

| # | Rule / area | Count | Disposition |
| --- | --- | --- | --- |
| B1 | `Security.NonceVerification.Recommended` | 29 | **Re-verified: false positive / accepted read-only.** Every state-changing handler verifies a nonce + capability; flagged reads are read-only request/notice flags or `$_SERVER` values. See B-detail §2. |
| B2 | `I18n.MissingTranslatorsComment` | 29 | **Not Fixed** — translatable strings with placeholders need translator comments. (Not re-verified in this pass; out of Priority 1 scope.) |
| B3 | `DB.DirectDatabaseQuery.DirectQuery` + `.NoCaching` | 28 + 28 | **Re-verified: safe, warning-level.** Queries are static (only `{$wpdb->...}` interpolation, never user input); cleanup DELETEs cannot be cached. See B-detail §1. |
| B4 | `Security.ValidatedSanitizedInput.InputNotSanitized` | 23 | **Re-verified: false positive.** Values are `$_SERVER`/read-only, sanitized on the next line, or sanitized per-field after `json_decode()`. See B-detail §2. |
| B5 | `Security.ValidatedSanitizedInput.MissingUnslash` | 13 | **Re-verified: false positive.** `$_SERVER` values are not slashed by WP; the rest are `absint()`ed or read-only. See B-detail §2. |
| B6 | `DB.PreparedSQL.NotPrepared` | 7 | **Re-verified: all false positives / 1 low-severity robustness note.** No exploitable SQL; the single action item is the dynamic `OPTIMIZE TABLE` identifier. See B-detail §1. |
| B7 | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 5 | **Re-verified: all false positives.** Same queries as B6; placeholders and input sources traced. See B-detail §1. |
| B8 | `Security.EscapeOutput.OutputNotEscaped` | 1 | **Re-verified: false positive.** `class-admin-bar.php:168` outputs `$notice`, which is only ever assigned an already-escaped `esc_html__()` string. See B-detail §2. |
| B9 | `I18n.UnorderedPlaceholdersText` | 8 | **Not Fixed** — placeholder ordering in translatable strings. |
| B10 | `missing_direct_file_access_protection` | 8 | **Stale / Not Fixed** — current includes all begin with `if ( ! defined( 'ABSPATH' ) ) exit;`. Re-run Plugin Check to confirm. |
| B11 | `WP.AlternativeFunctions.*` (`unlink` 22, `is_writable` 9, `rmdir` 8, `readfile` 3, `parse_url` 2, `rename` 1) | 45 | **Resolved (2026-10-13).** `is_writable`/`unlink` were migrated to `wp_is_writable()`/`wp_delete_file()` in rounds 2–4; the remaining `rmdir` calls were migrated to the WP_Filesystem API in the round-7 code pass; the 7 unavoidable calls (`parse_url` ×2, `unlink` ×1, `readfile` ×3, `rename` ×1) carry narrow, technically-justified `phpcs:ignore` exceptions. Plugin Check now reports **0** `AlternativeFunctions` findings. See Part B-implementation-7. |
| B12 | `WP.DevelopmentFunctions.error_log_error_log` | 12 | **Not Fixed (partly intentional)** — debug/fatal logging. Gate behind `WP_DEBUG` where not already. |
| B13 | `WP.DevelopmentFunctions.error_log_print_r` / `.var_export` | 2 + 1 | **Partly intentional** — `print_r` in a debug log call; `var_export` when generating `config.php`. |
| B14 | `PHP.DiscouragedFunctions` (`set_time_limit` 2) + `set_error_handler` 1 | 3 | **Not Fixed (intentional)** — long-running WebP/DB batches and libxml warning suppression. Document the reason. |
| B15 | `WP.DateTime.RestrictedFunctions.date_date` | 1 | **Not Fixed** — `date()` used when generating the static config banner (pre-WP context). |
| B16 | `Internal.LineEndings.Mixed` | 3 | **Resolved (round 3; re-verified 2026-10-13).** `class-admin.php`, `class-database-optimization.php` and `class-optimizer.php` were each normalised to LF-only (see R3-10). Re-checked this round: all three are CRLF=0 / LF>0 and the current 160-warning export contains **no** `LineEndings.Mixed` row. |
| B17 | `load_plugin_textdomain` | 1 | **Not Fixed** — retained in `wp-fastlayer.php:47`. Modern WP auto-loads; safe to remove but confirm with maintainer. |
| B18 | `WP.EnqueuedResources.NonEnqueuedScript` / `.NonEnqueuedStylesheet` | 1 + 1 | **Partially Verified** — see A3; likely stale, needs re-scan. |
| B19 | `readme_parser_warnings_too_many_tags` | 1 | **Stale?** — current `readme.txt` has exactly 5 tags (the max). Re-run to confirm. |
| B20 | `readme_parser_warnings_no_short_description_present` | 1 | **Stale?** — a Description section is present. Re-run to confirm. |
| B21 | `plugin_header_nonexistent_domain_path` | 1 | **Stale?** — header declares `Domain Path: /languages` and `languages/fastlayer.pot` exists. Re-run to confirm. |
| B22 | `Squiz.PHP.DiscouragedFunctions` | 2 | See B14 (overlaps `set_time_limit`). |

> **Re-scan required.** Several findings (B10, B18–B21) may already be fixed in
> the current source. Re-run Plugin Check against the current build before
> closing them. This is **Blocked** here because no PHP runtime is available.

---

## Part B-detail — Priority 1 re-verification against the current source

**Date:** 2026-10-12 · **Method:** direct source inspection of the current
`1.1.0` files at the line numbers below. PCP CSV line numbers were treated as
approximate and are re-mapped to current lines. **No runtime test was executed**
(no PHP available), so every row is "Source inspection only" unless stated
otherwise. Status vocabulary: **Exploitable**, **Safe by construction**,
**False positive**, **Low-severity robustness**, **Not Yet Tested**.

### 1. SQL and database findings

All findings below were traced end-to-end: for each query, where every
interpolated value comes from, and whether `$wpdb->prepare()` is required.

| PCP rule (CSV line) | Current file:line | Code context | Tainted input? | Risk | Recommended fix | Status |
| --- | --- | --- | --- | --- | --- | --- |
| `PreparedSQL.NotPrepared` ×2; `UnescapedDBParameter` ×1 (524) | `includes/class-database-optimization.php:524` | `` $wpdb->query( 'OPTIMIZE TABLE `' . sanitize_key( $table_name ) . '`' ); `` — `$table_name` comes from `$wpdb->get_results( 'SHOW TABLES', ARRAY_N )` at `:507`. | No — value originates from the DB schema, never from a request. | **Safe by construction** (not injectable). `sanitize_key()` is not a true identifier escaper: it lowercases and strips characters, so a mixed-case table name on a case-sensitive MySQL server could target the wrong/nonexistent table. | Validate `$table_name` against the `SHOW TABLES` result and wrap in backticks; optionally use the `%i` identifier placeholder on WP ≥ 6.2 with a backtick fallback for 6.0–6.1. | **Low-severity robustness** — Source inspection only |
| `PreparedSQL.NotPrepared` ×1 (762) | `includes/class-database-optimization.php:762` | `$wpdb->get_row( $sql, ARRAY_A )`; `$sql` is a literal defined at `:748-760`, interpolating only `{$wpdb->options}`. | No. | None. | Optionally inline `$sql` or add a justified `phpcs:ignore` (there is genuinely nothing to prepare). | **False positive** — Source inspection only |
| `PreparedSQL.NotPrepared` ×1; `UnescapedDBParameter` ×1 (1286) | `includes/class-database-optimization.php:1287` | `$wpdb->query( $sql )` in `execute_cleanup_query()`; `$sql` is supplied only by callers passing hardcoded literals (`:885, :1014, :1206, :1211, :1216, :1221, :1259, :1264, :1269, :1274, :1279`). | No — no caller passes request data. | None. | Add a justified `phpcs:ignore` or document that callers must pass literals. | **False positive** — Source inspection only |
| `PreparedSQL.NotPrepared` ×3; `UnescapedDBParameter` ×1 (1323) | `includes/class-webp.php:1323` | `$wpdb->get_var( call_user_func_array( array( $wpdb, 'prepare' ), $sql_args ) )`; `$sql_args` = SQL + `$mime_types` (`:1316-1321`), placeholders generated 1:1 with `array_fill`. | No — `$mime_types` is a hardcoded class property. | None. Correctly prepared; PCP cannot follow `call_user_func_array`. | Rewrite as `$wpdb->prepare( $sql, ...$mime_types )` or `$wpdb->prepare( $sql, $mime_types )` so static analysis can see it. | **False positive** — Source inspection only |
| `UnescapedDBParameter` ×1 (1074) | `includes/class-database-optimization.php:1075` (clause built at `:1071-1073`) | `$expired_clause` is a ternary between two hardcoded SQL fragments (`CAST(...) < UNIX_TIMESTAMP()` / `( v.option_id IS NULL OR ... )`), concatenated into the query. | No. | None. | Inline the two branches or add a justified `phpcs:ignore`. | **False positive** — Source inspection only |
| `UnescapedDBParameter` ×1 (1137) | `includes/class-database-optimization.php:1138` (built at `:1133-1134`) | `$wpdb->query( $wpdb->prepare( $sql, $chunk ) )`; `$sql` has `count($chunk)` `%s` placeholders and `$chunk` is the matching array. | No — array comes from resolved transient option names. | None. Correctly prepared. | Add a justified `phpcs:ignore` (already present on `:1138`). | **False positive** — Source inspection only |
| `DirectDatabaseQuery.DirectQuery` ×28 + `.NoCaching` ×28 | `includes/class-database-optimization.php` (all listed lines) | `SHOW TABLES`, `SHOW TABLE STATUS`, `SELECT COUNT(*)`, `get_col(ID)`, `DELETE`, `OPTIMIZE`. | No. | **Warning-level only.** Direct queries on core tables; the `DELETE`/`OPTIMIZE`/`SHOW` statements cannot be object-cached. | Add `wp_cache_*` only where a read is repeatable (e.g. counts on the admin screen); document the rest. Do not suppress. | **Safe** (warning) — Source inspection only |

**SQL conclusion:** no genuinely exploitable SQL issue. All 7
`PreparedSQL.NotPrepared` and 5 `UnescapedDBParameter` findings are false
positives or safe-by-construction, with **one low-severity robustness item**
(the `OPTIMIZE TABLE` identifier at `class-database-optimization.php:524`).

### 2. Nonce, sanitization, unslashing and escaping findings

**State-changing handlers — all verified to check a nonce *and* a capability:**

| Handler | Nonce | Capability |
| --- | --- | --- |
| `Admin::clear_cache` | `:523 check_admin_referer('fastlayer_clear_cache_nonce')` | `:524` |
| `Admin::handle_cache_debug_test` | `:141 check_admin_referer('fastlayer_cache_debug_test_nonce')` | `:142` |
| `Admin::preload*` | `:548`, `:570` | `:549`, `:571` |
| `Admin::test_imagekit` | `:579` | `:581` |
| `Admin::image_debug_report*` | `:711`, `:728` | `:713`, `:730` |
| `Admin::critical_css` / `bulk_webp` | `:1098`, `:1117`, `:1147`, `:1176`, `:1185` | `:1099`, `:1118`, `:1148`, `:1177`, `:1186` |
| `Admin::ajax_bulk_generate_webp_batch` (`$_POST['page'/'batch_size']` at `:1166-1167`) | `:1147 check_ajax_referer(...)` | `:1148` |
| `Admin_Bar::handle_action_request` (and `$_GET['redirect_to']` at `:106`) | `:98 wp_verify_nonce` | `:102` |
| `Database_Optimization` run/cleanup (`$_REQUEST['cleanup_items']` at `:550-551`) | `:27 check_admin_referer` | `:28` |
| `Database_Optimization::verify_ajax_request` | `:157-159 wp_verify_nonce` | `:151` |
| `Webp::dismiss_nginx_webp_notice` | `:1157 check_ajax_referer` | `:1159` |
| `CDN::handle_image_runtime_debug_collect` (`$_POST['entries']` at `:133`) | `:131 check_ajax_referer` | `:127` |

> **Note:** settings are saved through the WordPress Settings API
> (`register_setting( 'fastlayer_options', 'fastlayer_options', array( $this, 'sanitize_options' ) )`,
> `class-admin.php:54`), whose `options.php` endpoint verifies its own nonce;
> `sanitize_options()` receives already-validated input. The PCP row referencing
> `$_POST['wp_fastlayer_options']` at `class-admin.php:200` is **stale** — the
> current `:200` is a checkbox field name in an array, not a request read.

**Flagged reads that carry no nonce — disposition:**

| Current file:line | Read | Why it is acceptable | Status |
| --- | --- | --- | --- |
| `class-admin.php:454` | `$_GET['page']` | Sanitized + unslashed; read-only navigation. | **False positive** |
| `class-admin.php:506` | `$_GET['settings-updated']` | Core-set post-redirect flag; compared to `'true'`. Read-only. | **Low-severity robustness** (could sanitize) |
| `class-admin.php:1667, 1763-1764, 2322, 3156` | `$_GET[...]` notice flags | Sanitized + unslashed; map to fixed messages. | **False positive** |
| `class-admin.php:3409-3410` | `$_GET['section']` | Sanitized (`sanitize_key`), unslashed, escaped (`esc_attr`). | **False positive** |
| `class-admin-bar.php:129-133` | `$_GET['fastlayer_admin_bar_message']` | Capability-checked, sanitized; maps to fixed strings. | **False positive** |
| `advanced-cache.php:236, 239` | `$_GET['preview'/'s']` | Pre-WordPress cache-skip detection; read-only. | **False positive** (intentional) |
| `class-optimizer.php:1882, 1922` | `$_GET['preview'/'elementor-preview']` | Read-only context detection. | **False positive** |
| `class-page-cache.php:227-229, 535-537` | `$_SERVER['REQUEST_METHOD']` | `$_SERVER` is not slashed by WP; compared only. | **False positive** |
| `class-page-cache.php:420, 654`; `class-webp.php:140`; `class-optimizer.php:1895, 1904`; `class-cdn.php:580-581`; `advanced-cache.php:81,224,271,280,297,298`; `class-webp.php:2972, 3123-3127, 3164-3168, 3271, 3326-3334` | `$_SERVER[...]` | Either unslashed already, or pre-WordPress (`advanced-cache.php`) where `wp_unslash()` is unavailable; used for comparison/validation, not output. | **False positive** (intentional) |
| `class-database-optimization.php:550-551` | `$_REQUEST['cleanup_items']` | Nonce+capability verified upstream; values treated as booleans against an allowlist of known keys. | **False positive** |
| `class-webp.php:3334` | `$_SERVER['HTTP_IF_MODIFIED_SINCE']` | Passed to `strtotime()`; result compared numerically. | **False positive** (low) |
| `class-admin-bar.php:106` | `$_GET['redirect_to']` | Unslashed here, then `sanitize_redirect_url()` (`:217-227`) applies `esc_url_raw()` + `wp_validate_redirect()`. | **False positive** |
| `class-cdn.php:133` | `$_POST['entries']` | JSON that is `json_decode()`d and sanitized per field (`absint`, `esc_url_raw`) at `:148-167`. | **False positive** |

**Output escaping:** `Security.EscapeOutput.OutputNotEscaped` ×1 —
`class-admin-bar.php:168` prints `$notice`, which is assigned only
`esc_html__()`-escaped strings at `:139-160`. **False positive** (add an explicit
`esc_html( $notice )` at the `printf` if the scanner should be silenced).

**Nonce/sanitize conclusion:** no confirmed exploitable issue. Every
state-changing action is nonce- and capability-protected; all flagged inputs are
either server-controlled, read-only, sanitized on the next line, or sanitized
per field. The only optional hardening is sanitizing the read-only
`$_GET['settings-updated']` at `class-admin.php:506`.

### 3. Verification status summary

| Group | Findings | Exploitable | False positive | Low-severity robustness | Blocked (needs tools) |
| --- | ---: | ---: | ---: | ---: | ---: |
| SQL (`NotPrepared` + `UnescapedDBParameter`) | 12 | 0 | 11 | 1 | Re-scan blocked |
| Nonce | 29 | 0 | 28 | 1 | Re-scan blocked |
| Input sanitization | 23 | 0 | 23 | 0 | Re-scan blocked |
| Unslash | 13 | 0 | 13 | 0 | Re-scan blocked |
| Escape output | 1 | 0 | 1 | 0 | Re-scan blocked |

> All rows are **source inspection only**. Confirmation by Plugin Check, WPCS, or
> a live exploit attempt is **Blocked** (no PHP/WP-CLI/PHPCS in this workspace).

---

## Part B-implementation — Fixes applied (2026-10-12)

Records changes actually made in response to the Part B-detail re-verification.
**No PHP runtime was available**, so every change below is **source-inspection
verified only** — not runtime-tested.

| Fix | File · function | Change | Status |
| --- | --- | --- | --- |
| **C-2** | `includes/class-admin.php` · `render_settings_saved_notice()` | The read-only `$_GET['settings-updated']` flag is now read as `isset( … ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : ''` and compared to `'true'`. Missing parameter resolves to `''` so the notice is not shown; save/notice behavior is unchanged. | **Applied** |
| **C-1** | `includes/class-database-optimization.php` · `optimize_all_tables()` | Table names are now collected, case-preserved, from `SHOW TABLES` into an allowlist (empty and NUL-byte names rejected); `sanitize_key()` is no longer used as an identifier escaper. On WordPress ≥ 6.2 the query uses `$wpdb->prepare( 'OPTIMIZE TABLE %i', $table_name )` (gated by `$wpdb->has_cap( 'identifier_placeholders' )`); on WordPress 6.0–6.1 it uses the new `quote_identifier()` helper, which wraps the name in backticks and doubles any embedded backtick. No request value can reach the query. | **Applied** |
| **B6 WebP prepare** | `includes/class-webp.php` · `get_webp_attachment_count()` | Replaced `call_user_func_array( array( $wpdb, 'prepare' ), $sql_args )` with a direct `$wpdb->prepare( $sql, $mime_types )`. Equivalent because `wpdb::prepare()` expands a single array argument; SQL text and placeholders are unchanged. Lets static analysis see the prepared query. | **Applied** |
| **B8 admin-bar output** | `includes/class-admin-bar.php` · `display_success_notice()` | **Intentionally not changed.** `$notice` is assigned only `esc_html__()`-escaped strings (or `''`), so adding `esc_html()` at the `printf` would double-escape any translation containing an entity (e.g. `&amp;`). The PCP `OutputNotEscaped` row is a false positive; escaping is already correct. | **Skipped (documented)** |
| **B6/B7 static SQL** | `includes/class-database-optimization.php` · `execute_cleanup_query()` | Added a docblock stating `$sql` must be a hardcoded literal (no request data, nothing to bind). No new suppression directives were added; the two pre-existing narrowly-scoped `phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared` comments (`:1121`, `:1175`) were left intact. | **Applied (docs-in-code)** |
| **B1/B4/B5 nonce/sanitize** | — | No code change. Re-verification found no exploitable issue and no unnecessary nonce was added to read-only flows; the Settings API path was left untouched. | **No change needed** |
| **Phase 3 native calls** | `advanced-cache.php`, `uninstall.php` | No change. Intentional pre-WordPress native calls preserved. | **No change** |

**Remaining findings after these edits:** A9 (recurring `WP_CACHE` warning)
remains **Not Fixed / Not Yet Tested**; B2 (translator comments) remains **Not
Fixed**; B3 (DirectQuery/NoCaching) remains a **warning-level** item; all other
Part B rows remain false positives / accepted patterns as analysed above. The
`OPTIMIZE TABLE` fallback branch (WP 6.0–6.1) produces a
`PreparedSQL.NotPrepared` warning because a dynamically quoted identifier cannot
be verified statically; round 2 (see `Part B-implementation-2`, R2-4) adds a
single narrowly-scoped, justified suppression on that one line.

**Tools unavailable / checks not run:** `php`, `composer`, `phpcs`, `wp`
(WP-CLI) are not installed; PHP lint, PHPCS/WPCS, PHPUnit and a Plugin Check
re-scan were **not run**. Only `node --check` was executed (JS files). See
Part D.

---

## Part B-implementation-2 — Plugin Check round 2 (2026-10-12)

Second pass over the Plugin Check findings. Same caveat as above: **no PHP
runtime** in this workspace, so everything below is **source-inspection verified
only** and not runtime-tested. Line references are to the working copy at the
time of the edit.

| # | Finding (rule) | File | Before → After | Status |
| --- | --- | --- | --- | --- |
| **R2-1** | `WordPress.WP.I18n.MissingTranslatorsComment` | `includes/class-webp.php` | `sprintf( __( '… uploaded image "%s".', … ), basename( $file ) )` had no translator note → added `/* translators: %s: File name of the uploaded image, for example "photo.jpg". */` immediately above the call | **Applied** |
| **R2-2** | `WordPress.Security.EscapeOutput.OutputNotEscaped` | `includes/class-admin-bar.php` `display_success_notice()` | `$notice` was escaped at assignment with `esc_html__()` and printed unescaped via `printf( … %2$s …, $notice )` → assignments now use `__()` and `printf()` prints `esc_html( $notice )`. Escaping moved to the point of output, so nothing is double-escaped and the displayed text is unchanged | **Applied** |
| **R2-3** | `WordPress.DateTime.RestrictedFunctions.date_date` | `includes/class-bootstrap.php` `write_static_config()` | `date( 'Y-m-d H:i:s' )` in the generated-config comment → `gmdate( 'Y-m-d H:i:s' )`. WordPress forces the PHP default timezone to UTC (`wp-settings.php`), so the timestamp is identical; the value is a human-readable comment only and has no functional role | **Applied** |
| **R2-4** | `WordPress.DB.PreparedSQL.NotPrepared` | `includes/class-database-optimization.php` `optimize_all_tables()` | WP 6.0–6.1 fallback `$wpdb->query( 'OPTIMIZE TABLE ' . self::quote_identifier( $table_name ) )` → added a **single-line, justified** `phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- …`. The `%i` path (WP ≥ 6.2) is unchanged; `%s` cannot be used because it would quote the identifier as a string literal and break the SQL. `$table_name` is never request data: it comes only from the `SHOW TABLES` allowlist | **Applied (scoped, justified suppression)** |
| **R2-5** | `…file_system_operations_is_writable` | `class-admin.php` (×2), `class-bootstrap.php` (×2), `class-page-cache.php` (×1), `class-webp.php` (×4) | `is_writable( $path )` → `wp_is_writable( $path )` (`@since 3.6.0`; wraps the Windows ACL workaround). Boolean result and every call site unchanged | **Applied (9 sites)** |
| **R2-6** | `…unlink_unlink` | `class-admin.php`, `class-admin-bar.php`, `class-bootstrap.php`, `class-page-cache.php`, `uninstall.php` | `@unlink( $file )` → `wp_delete_file( $file )` (`@since 4.2.0`), only where the return value was unused. `class-admin.php`'s cache self-test now deletes with `wp_delete_file()` and verifies with `file_exists()`, because `wp_delete_file()` only gained a return value in WordPress 6.7 (the plugin's minimum is 6.0) | **Applied (22 sites)** |
| **R2-7** | `…parse_url_parse_url` | `advanced-cache.php` (×2) | `parse_url()` → **not changed.** `wp_parse_url()` lives in `wp-includes/http.php`, which WordPress requires at `wp-settings.php` line 266 — after the drop-in is included at line 100. The function does not exist when this file runs, so the native call is required | **Skipped (documented)** |
| **R2-8** | `…file_system_operations_rmdir` | `class-admin.php`, `class-admin-bar.php`, `class-bootstrap.php`, `uninstall.php` | `@rmdir()` → **not changed.** There is no `wp_rmdir()`. `WP_Filesystem::rmdir()` requires `WP_Filesystem()` initialisation (which can demand FTP credentials), returns silently on failure, and would replace the existing explicit error handling | **Skipped (documented)** |
| **R2-9** | `…file_system_operations_readfile` | `advanced-cache.php`, `class-page-cache.php`, `class-webp.php` | `readfile()` → **not changed.** No `wp_readfile()` exists; `WP_Filesystem::get_contents()` buffers the whole file in memory, which is wrong for streaming a cached page or image. `advanced-cache.php` additionally runs before WordPress is loaded | **Skipped (documented)** |
| **R2-10** | `…rename_rename` | `class-bootstrap.php` | `@rename( $staged, $config_file )` → **not changed.** The staged-file + `rename()` sequence is deliberate: a `rename()` within one filesystem is atomic, whereas `WP_Filesystem::move()` is not. Required by the `wp-config.php` write-safety contract (`AGENTS.md §5`) | **Skipped (documented)** |

### Text domain / plugin slug (task 1)

**Finding:** Plugin Check expects the capitalised `FastLayer`, while the plugin
uses the lowercase text domain `fastlayer` everywhere (`wp-fastlayer.php`
header, all `__()`/`esc_html__()`/`_n()` calls,
`load_plugin_textdomain( 'fastlayer', … )`, and `languages/fastlayer.pot`).

**Analysis:**

- The local plugin directory is **`FastLayer`** (capitalised). Plugin Check
  derives the expected text domain from the plugin slug, which it takes from the
  directory/main-file name, so a capitalised directory makes the checker expect
  `FastLayer`.
- WordPress.org slugs are always lowercase (`fastlayer`). The **text domain is
  already correct** and must **not** be changed to uppercase.
- The related `plugin_header_nonexistent_domain_path` row
  (`Found: "languages"`) appears **stale**: `languages/fastlayer.pot` exists in
  the working copy, so `Domain Path: /languages` resolves. Re-run Plugin Check to
  confirm the row is gone.

**Safest way to normalise the directory (documented only; not performed here):**

1. Deactivate FastLayer under *Plugins* (optional, but recommended).
2. Rename `wp-content/plugins/FastLayer` → `wp-content/plugins/fastlayer` with
   SFTP, the hosting file manager, or SSH `mv`. No plugin file needs editing:
   `plugin_basename()`, `plugin_dir_path()` and `plugin_dir_url()` resolve the
   path at runtime.
3. Reactivate FastLayer. WordPress stores active plugins as
   `folder/mainfile.php`, so the old path is dropped and the plugin must be
   reactivated — expected and non-destructive.
4. No data migration is needed: settings live in the `fastlayer_options` option
   (not on disk), and the `advanced-cache.php` drop-in plus the `WP_CACHE` define
   are path-independent, so they keep working.

Do **not** rename the folder mid-request, and do **not** change the text domain
to `FastLayer`.

### `load_plugin_textdomain()` (PCP `DiscouragedFunctions`)

Left unchanged. It is only discouraged for WordPress.org-hosted plugins, and
`AGENTS.md §6` requires maintainer confirmation before removing it (the fix
would be to rely on the `Domain Path: /languages` header plus `Requires at
least: 6.0`).

---

## Part B-implementation-3 — Plugin Check round 3 (2026-10-12)

For the first time in this workspace a full PHP toolchain was available and
Plugin Check was **actually executed** (not source-inspected): LocalWP PHP
7.4.30, the plugin-check 2.1.0 installation with its bundled PHPCS, and WP-CLI
2.12.0 against the live local site (WordPress 7.1.3, MySQL via
`-d mysqli.default_port=10027`).

- **Live scan 1 (before fixes): 679 findings — 500 ERROR, 179 WARNING.**
- **Live scan 2 (after fixes): 625 findings — 455 ERROR, 170 WARNING.**

### Text-domain mismatch — root cause confirmed (task 1)

Reported finding: `includes/class-admin-bar.php:48` —
`WordPress.WP.I18n.TextDomainMismatch — Expected 'FastLayer' but got 'fastlayer'`.

Root cause confirmed from the installed `plugin-check` source
(`includes/Plugin_Context.php`): the expected text domain is the plugin slug,
and `Plugin_Context::slug()` defaults to `basename( dirname( $main_file ) )` —
i.e. the **plugin directory name**. The local folder is `FastLayer`, so the
checker expects `FastLayer`.

Reproduced empirically with the bundled PHPCS
(`phpcs --standard=WordPress --sniffs=WordPress.WP.I18n --runtime-set text_domain <d>`
over `includes/class-admin-bar.php`):

- `text_domain=FastLayer` → 15 `TextDomainMismatch` errors;
- `text_domain=fastlayer` → **0 findings** (exit 0).

Running the same sniff over **all 11 PHP files** with `text_domain=fastlayer`
also returns **0 findings** (exit 0). The single `textdomain_mismatch` header
row is the same root cause (header vs slug comparison).

**Classification: environment / false positive.** The text domain is correct
and must stay lowercase `fastlayer`. The finding clears automatically on
WordPress.org (lowercase slug/folder `fastlayer/`) or when the scan is given an
explicit lowercase slug. The local folder rename documented in the round-2
section above was **not** performed.

### Fixes applied in round 3 (verified by re-scan)

| # | Finding | File | Change | Result |
| --- | --- | --- | --- | --- |
| **R3-1** | `WordPress.WP.I18n.MissingTranslatorsComment` (28) | `class-admin.php` (17), `class-database-optimization.php` (6), `class-webp.php` (5) | `/* translators: … */` notes added above every placeholder-bearing translatable string (for real `sprintf()` calls and for the JS-template tokens alike) | 28 → 0 |
| **R3-2** | `WordPress.WP.I18n.UnorderedPlaceholdersText` — real sprintf strings (4) | `class-database-optimization.php` (`%d,%d` → `%1$d,%2$d`; `%s,%s` → `%1$s,%2$s`), `class-admin.php` (GD support detail, 4× `%s` → `%1$s…%4$s`) | Reordered to numbered placeholders so re-translation stays correct | 8 → 0 (with R3-3) |
| **R3-3** | `…UnorderedPlaceholdersText` — JS template strings (4) | `class-admin.php` (`bulk_summary_template`, `bulk_log_batch_completed`, `bulk_status_template`, DB-cleanup `description`) | `%word%` tokens are **client-side JS substitution variables**, not printf placeholder ordering — translators note added plus a **line-scoped** `phpcs:ignore …UnorderedPlaceholdersText` stating exactly that | see R3-2 |
| **R3-4** | `WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder` (1) | `class-database-optimization.php` `optimize_all_tables()` | `%i` only executes on WP ≥ 6.2 (`$wpdb->has_cap('identifier_placeholders')`); line-scoped `phpcs:ignore` documents the guard; WP 6.0–6.1 use the `quote_identifier()` branch | 1 → 0 |
| **R3-5** | `WordPress.DB.PreparedSQL.NotPrepared` (3) | `class-database-optimization.php:812,1350`; `class-webp.php:1324` | Static aggregate query, static cleanup statement, and a query that **is** `$wpdb->prepare()`d (sniff cannot see through the `$sql` variable) → three line-scoped, justified suppressions | 3 → 0 |
| **R3-6** | `PluginCheck.Security.DirectDB.UnescapedDBParameter` (4) | `class-database-optimization.php` | All four sites are trusted identifiers or static fragments (SHOW TABLES allowlist, `$wpdb->options`, static DELETE statements, `$expired_clause` fixed ternary) → line-scoped suppressions with reasons | 4 → 0 |
| **R3-7** | `WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet` / `NonEnqueuedScript` (2 + 2) | `class-optimizer.php` | Hand-built `<link>`/`<script>` fallbacks replaced by a pass-through of the original uncombined tag for the (practically unreachable) registry-unavailable path; the primary path still prints through `WP_Styles::do_item()` / `WP_Scripts::do_item()` | 4 → 0 |
| **R3-8** | `WordPress.WP.EnqueuedResourceParameters.MissingVersion` (2) | `class-optimizer.php` | `wp_register_style()` / `wp_register_script()` version arg `null` → `FASTLAYER_VERSION` | 2 → 0 |
| **R3-9** | readme short-description warning (1) | `readme.txt` | One-line short description added between the header block and `== Description ==` | 1 → 0 |
| **R3-10** | `Internal.LineEndings.Mixed` (3) | `class-admin.php`, `class-database-optimization.php`, `class-optimizer.php` | Each file was entirely CRLF except one stray LF; whole file normalised to LF (per `AGENTS.md §3`) | 3 → 0 |

### Validation executed after the fixes

| Check | Command | Result |
| --- | --- | --- |
| PHP lint | LocalWP `php -l` on all 11 PHP files | 11/11 clean |
| I18n sniff, correct domain | bundled `phpcs --sniffs=WordPress.WP.I18n --runtime-set text_domain fastlayer` (all 11 files) | 0 findings, exit 0 |
| I18n sniff, folder-derived domain | same command with `text_domain=FastLayer` | 15 errors on one file → confirms the folder-name cause |
| Full Plugin Check | `wp plugin check FastLayer` (WP-CLI 2.12.0 + plugin-check 2.1.0) | 679 → 625 |

### Remaining findings after round 3 (classification)

| # | Rule | Count | Classification |
| --- | --- | ---: | --- |
| 1 | `WordPress.WP.I18n.TextDomainMismatch` + `textdomain_mismatch` header | 439 E + 1 W | **Environment** — expected domain = folder name (`FastLayer`); correct domain is `fastlayer`. Clears with the lowercase slug; no code change |
| 2 | `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` | 33 → 32 | **Intentional** — `advanced-cache.php`/`uninstall.php` run before/outside the full bootstrap and use documented prefixed-but-global variables. The `$_wpfl_bootstrap_file` variable in `wp-fastlayer.php` was removed in round 4 (see `Part B-implementation-4`, R4-2), taking the count from 33 to 32 |
| 3 | `WordPress.DB.DirectDatabaseQuery.DirectQuery` + `NoCaching` | 29 + 29 | **By design** — the database cleanup tool must query directly; results are request-scoped and must not be cached |
| 4 | `WordPress.Security.NonceVerification.Recommended` | 29 | **Read-only displays** — `$_GET` reads for navigation/notices are sanitized and render-only; every state-changing handler verifies a nonce + capability (e.g. `class-cdn.php` debug-collect verifies both before reading `$_POST`) |
| 5 | `ValidatedSanitizedInput.InputNotSanitized` (20) / `MissingUnslash` (10) | 30 | **False positive / intentional** — `$_SERVER` fields used only for matching/cache-keys, a JSON body validated after `json_decode()`, and `advanced-cache.php` (runs before `wp_unslash()` exists) |
| 6 | `WordPress.PHP.DevelopmentFunctions.error_log_error_log` | 12 | **Intentional** — error paths (module-load catch blocks) plus guarded debug logging via `fastlayer_debug_log()` |
| 7 | `…file_system_operations_rmdir/readfile`, `parse_url_parse_url`, `unlink_unlink`, `rename_rename` | 16 E | **Intentional, documented per-call** — see R2-7…R2-10 (atomic `rename()` for the wp-config contract, streaming `readfile()` for served cache, drop-in runs before `wp_parse_url()`/`wp_delete_file()` exist, no `wp_rmdir()`) |
| 8 | `Squiz.PHP.DiscouragedFunctions.Discouraged` (`set_time_limit`) | 2 | **Intentional** — `function_exists()` + `@` guard before long WebP batch runs |
| 9 | `…error_log_set_error_handler` / `print_r` / `var_export` | 3 | **Intentional** — `set_error_handler('__return_false')` wraps a regex probe; `print_r` is `WP_DEBUG`-only; `var_export` generates the static `config.php` content |
| 10 | `PluginCheck.CodeAnalysis…load_plugin_textdomainFound` | 1 | **Documented** — retained for older-WP compat; needs maintainer confirmation (see round 2) |
| 11 | `unexpected_markdown_file` (AGENTS.md) | 1 | **Packaging** — dev file; exclude from the production ZIP (`RELEASE-CHECKLIST.md §5`) |

**Net:** excluding the 439 environment text-domain rows, the live scan reports
exactly **16 ERRORs** — all the documented native filesystem/`parse_url()` calls
(row 7) — and the remaining rows are WARNINGs.

**Suppression inventory (round 3):** 9 `phpcs:ignore` comments (plus 2
pre-existing database ones given reasons this round) — every one is
single-line/single-statement scoped and carries a technical reason; there are
no blanket suppressions.

---

## Part B-implementation-4 — Post-rename audit (round 4, 2026-10-12)

The plugin folder was renamed **`FastLayer` → `fastlayer`**. Unlike rounds 1–3,
this round ran entirely against the **live local site** with the full toolchain
actually executing PHP lint, PHPCS and a full Plugin Check scan.

### Environment (verified)

| Item | Value |
| --- | --- |
| Site | `http://fastlayer.local/` (HTTP 200 during the audit) |
| Active plugin path | `…/wp-content/plugins/fastlayer` (lowercase; canonical name confirmed via `realpath`/`dir /x`/.NET) |
| WordPress | 7.1.3 |
| Web server | nginx |
| Database | MySQL 8.4 on port **10027** |
| PHP | 7.4.30 (LocalWP) |
| WP-CLI | 2.12.0 |
| Plugin Check | 2.1.0 (installed as a plugin, with its bundled PHPCS 3.13.6) |

### Backup (created before any change)

Taken outside the active plugin directory; pre-existing backups were left
untouched:

| Artefact | Purpose |
| --- | --- |
| `…\Temp\opencode\fastlayer-backups\fastlayer-plugin-20261012-181543.zip` (24 entries) | Full plugin snapshot |
| `…\fastlayer-backups\wp-config-20261012-181740.php.bak` | `wp-config.php` snapshot |
| `…\fastlayer-backups\active_plugins-20261012-181740.json` | `active_plugins` row snapshot |

### Root cause: plugin showed as inactive after the rename

Because WordPress stores active plugins as `folder/mainfile.php`, the rename left
a **stale `FastLayer/wp-fastlayer.php`** entry in the `active_plugins` option, so
fastlayer read as inactive. Fixed at the root (not just by toggling):

- Re-activated the plugin, then removed the stale capitalised entry (via
  `wp eval-file`, so JSON was written by PHP rather than quoted through
  PowerShell).
- Resulting option: `["fastlayer/wp-fastlayer.php","plugin-check/plugin.php"]`.
- `plugin_basename()` on the main file now returns the lowercase
  `fastlayer/wp-fastlayer.php` (previously `FastLayer/wp-fastlayer.php`).

This is the same class of change documented in `AGENTS.md §1`; reactivation and
stale-entry cleanup are expected and non-destructive.

### Baseline scan (post-rename) and reconciliation

The first full scan **after** the lowercase rename (before any round-4 code
change):

- **185 findings — 16 ERROR, 169 WARNING.**

Reconciles exactly with round 3: the round-3 scan reported **625** findings, of
which **440** were the environment **text-domain** rows (439 `TextDomainMismatch`
ERRORs + 1 `textdomain_mismatch` header WARNING). With the lowercase folder,
those rows are **gone**: **625 − 440 = 185**. Confirmed: **0**
`TextDomainMismatch` / `textdomain_mismatch` findings remain.

### Fixes applied in round 4 (verified by re-scan)

| # | Finding | File | Change | Result |
| --- | --- | --- | --- | --- |
| **R4-1** | Stale `active_plugins` entry (plugin inactive) | `active_plugins` option | Re-activated and removed the stale `FastLayer/wp-fastlayer.php` row | Option now `["fastlayer/wp-fastlayer.php","plugin-check/plugin.php"]` |
| **R4-2** | `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` | `wp-fastlayer.php` | Removed the legacy-named global `$_wpfl_bootstrap_file` (and its `unset()`); the bootstrap path is inlined in the `file_exists()`/`require_once`/`error_log()` calls | `PrefixAllGlobals` **33 → 32** |
| **R4-3** | Doc/UI name consistency | `readme.txt` (line 17) | `FastLayer` → `fastlayer` in the folder-name reference | Doc consistency |
| **R4-4** | Doc consistency | `docs/RELEASE-CHECKLIST.md` (§5.1) | ZIP root reference `FastLayer/` → `fastlayer/` | Doc consistency |

**Net after round 4: 184 findings — 16 ERROR, 168 WARNING** (185 − 1).

### `WP_CACHE` activate/deactivate cycle — executed

The A9-adjacent behaviour was exercised on the live site:

1. **Deactivate** → the `advanced-cache.php` drop-in was removed and the
   FastLayer-owned `WP_CACHE` line was removed from `wp-config.php`; no foreign
   define was touched.
2. **Reactivate** → the drop-in was reinstalled and the marked `WP_CACHE` line
   was restored; **no duplicate** line was created.
3. `wp-config.php` still **lints clean**; site returned **200**.
4. On the **next request**, `defined('WP_CACHE')` evaluated to **`true`**, so the
   A9 warning condition is false from the next request onward (see round-1 A9).
   The activation request itself was not separately instrumented.

A benign one-byte **CRLF → LF** normalisation in the edited region of
`wp-config.php` was observed (expected from the atomic stage-and-`rename()`
writer).

### Preserved fixes re-verified (present in the current source)

| Fix | Evidence |
| --- | --- |
| Translator comments | **39** `translators:` comments present |
| Numbered placeholders | **21** source lines carry `%1$s`/`%2$d`-style placeholders (**53** occurrences in total) |
| DB identifier safety | `quote_identifier()`, `%i`, `$wpdb->has_cap( 'identifier_placeholders' )` present |
| Filesystem handling | `wp_delete_file` ×23, `wp_is_writable` ×9 |
| Admin-bar escaping | `esc_html( $notice )` at `class-admin-bar.php:172` |
| Version constant | `FASTLAYER_VERSION` = `1.1.0` (unchanged) |

### Validation executed after the round-4 edits

| Check | Command | Result |
| --- | --- | --- |
| PHP lint | LocalWP `php -l` on all 11 PHP files | **11/11 clean** |
| JS syntax | `node --check assets/js/*.js` | **4/4 clean** |
| Full Plugin Check (before) | `wp plugin check fastlayer` (post-rename, pre-fix) | **185** (16 E / 169 W) |
| Full Plugin Check (after) | `wp plugin check fastlayer` (after R4-2) | **184** (16 E / 168 W) |
| WPCS (bundled PHPCS) | `--standard=WordPress` over the plugin | 14 139 E / 1 323 W — dominated by `Generic.WhiteSpace.DisallowSpaceIndent` (13 189) from the intentional 4-space PHP indent; not a new regression versus round 3 |

### Remaining findings after round 4 (all classified)

| # | Rule | Count | Classification |
| --- | --- | ---: | --- |
| 1 | `PrefixAllGlobals.NonPrefixedVariableFound` | 32 | **Intentional** — `advanced-cache.php`/`uninstall.php` run before/outside the bootstrap (see the round-3 note for this rule) |
| 2 | `DB.DirectDatabaseQuery.DirectQuery` + `.NoCaching` | 29 + 29 | **By design** — the DB cleanup tool must query directly; results are request-scoped |
| 3 | `Security.NonceVerification.Recommended` | 29 | **Read-only displays** — every state-changing handler verifies nonce + capability |
| 4 | `ValidatedSanitizedInput.InputNotSanitized` (20) / `MissingUnslash` (10) | 30 | **False positive / intentional** — `$_SERVER` used only for matching/cache keys, JSON validated after `json_decode()`, `advanced-cache.php` (pre-`wp_unslash()`) |
| 5 | `…file_system_operations_rmdir` (9), `.readfile` (3), `parse_url_parse_url` (2), `unlink_unlink` (1), `rename_rename` (1) | **16 E** | **Intentional, documented per-call** — see R2-7…R2-10 (atomic `rename()`, streaming `readfile()`, drop-in runs before `wp_parse_url()`/`wp_delete_file()` exist, no `wp_rmdir()`) |
| 6 | `error_log_error_log` (12), `error_log_set_error_handler` (1), `error_log_print_r` (1), `error_log_var_export` (1) | 15 | **Intentional** — error paths, `WP_DEBUG`-gated logging, `var_export` for the static config |
| 7 | `Squiz.PHP.DiscouragedFunctions.Discouraged` (`set_time_limit`) | 2 | **Intentional** — `function_exists()` + `@` guard before long WebP batch runs |
| 8 | `PluginCheck.CodeAnalysis…load_plugin_textdomainFound` | 1 | **Documented** — retained for older-WP compat; needs maintainer confirmation |
| 9 | `unexpected_markdown_file` (`AGENTS.md`) | 1 | **Packaging** — dev file; exclude from the production ZIP (`RELEASE-CHECKLIST.md §5`) |
| | **TOTAL** | **184** | 168 W + **16 E** (all native filesystem/`parse_url()`, row 5) |

**Suppression inventory (round 4):** unchanged from round 3 — 9 `phpcs:ignore`
comments (plus the 2 pre-existing database ones), all single-line/scoped with a
technical reason. Round 4 added **no** new suppression; it removed a global
instead.

The text-domain findings are now **resolved at the source** (lowercase folder),
so `AGENTS.md §6`'s "expected domain = folder name" note is satisfied in
practice. The plugin slug and text domain remain `fastlayer`.

---

## Part B-implementation-5 — Error & security audit (round 5, 2026-10-13)

Round 5 audited the **16 remaining Plugin Check ERRORs** and re-verified the
reported security warnings against the current source, then re-ran the whole
toolchain. Unlike rounds 1–2, every check below was **actually executed** in
this workspace against the live local site.

### Environment (verified)

| Item | Value |
| --- | --- |
| Site | `http://fastlayer.local/` (HTTP 200 for GET and HEAD during the audit) |
| Plugin path | `…/wp-content/plugins/fastlayer` (active) |
| WordPress | 7.1.3 |
| Web server | nginx |
| Database | MySQL 8.4 on port 10027 |
| PHP | 7.4.30 (LocalWP) |
| WP-CLI | 2.12.0 |
| Plugin Check | 2.1.0 (installed as a plugin, with its bundled PHPCS 3.13.6) |

### Backup (created before any change)

Taken outside the active plugin directory; pre-existing backups were left
untouched:

| Artefact | Purpose |
| --- | --- |
| `…\Temp\opencode\fastlayer-backups\fastlayer-plugin-r5-20261013-101150.zip` (24 entries) | Full plugin snapshot |
| `…\fastlayer-backups\pcp-r5-before.txt` | Plugin Check report before the round-5 edit |
| `…\fastlayer-backups\pcp-r5-after.txt` | Plugin Check report after the round-5 edit |

### Baseline

A fresh Plugin Check on the unmodified source reproduced round 4 exactly:
**184 findings — 16 ERROR, 168 WARNING.** No drift.

### The 16 ERRORs — reviewed individually

All 16 are `WordPress.WP.AlternativeFunctions.*` calls in a pre-WordPress or
native-filesystem context. Each was re-checked against the current source at the
cited line; every one is an **accepted exception**, not a defect, and none is
suppressed.

| # | Rule | File:line | Context | Verdict |
| --- | --- | --- | --- | --- |
| 1 | `parse_url_parse_url` | `advanced-cache.php:45` | `fastlayer_host_from_url()` — the drop-in runs **before WordPress loads**, so `wp_parse_url()` is not defined | Justified |
| 2 | `parse_url_parse_url` | `advanced-cache.php:112` | `fastlayer_strip_url_domain()` — same reason; the inline comment (`:108-111`) states it mirrors `Page_Cache::strip_url_domain()` so both cache paths agree | Justified |
| 3 | `unlink_unlink` | `advanced-cache.php:330` | Deletes an **expired** cache file; pre-WP (`wp_delete_file()` undefined), `@`-guarded, and the path is pre-validated by `fastlayer_is_safe_path()` | Justified |
| 4 | `file_system_operations_readfile` | `advanced-cache.php:339` | Streams an already-validated cache file; `wp_readfile()` does not exist | Justified |
| 5 | `file_system_operations_rmdir` | `class-admin-bar.php:317` | Empties then removes cache **sub**directories; WordPress has no `wp_rmdir()` | Justified |
| 6 | `file_system_operations_rmdir` | `class-admin-bar.php:326` | Removes the now-empty cache root | Justified |
| 7 | `file_system_operations_rmdir` | `class-admin.php:1538` | Removes empty minified-asset directories after `wp_delete_file()` emptied them | Justified |
| 8 | `rename_rename` | `class-bootstrap.php:596` | Atomic stage-and-`rename()` write of `wp-config.php` (AGENTS.md §5(2)); `rename()` is **required** for atomicity | Justified |
| 9 | `file_system_operations_rmdir` | `class-bootstrap.php:772` | Removes the empty **legacy** `logs/` directory | Justified |
| 10 | `file_system_operations_readfile` | `class-page-cache.php:493` | `serve_cache()` streams the validated cache file | Justified |
| 11 | `file_system_operations_readfile` | `class-webp.php:3323` | `send_webp_file()` streams the WebP binary | Justified |
| 12–16 | `file_system_operations_rmdir` | `uninstall.php:62,76,90,137,147` | Uninstall removes the emptied plugin-generated directories; no `wp_rmdir()` exists | Justified |

*(The cited `class-page-cache.php:493` line shifted to `:494` after the R5-1
insertion above it; the finding is the same `readfile()` call.)*

### Security findings — re-verified; one genuine gap fixed

Every reported security warning was traced against the current source:

- **`NonceVerification.Recommended` (29)** — read-only display / `$_GET` reads.
  All **18** state-changing handlers already verify a nonce **and** capability
  (`check_admin_referer()` / `check_ajax_referer()` / `wp_verify_nonce()`); no
  handler was missing one. Accepted.
- **`DirectDatabaseQuery.DirectQuery` + `.NoCaching` (29 + 29)** — static
  queries against core `$wpdb->` tables in the DB-cleanup tool; request-scoped
  results, no user-supplied SQL. By design.
- **`InputNotSanitized` (20) / `MissingUnslash` (10)** — traced to `$_SERVER`
  reads used only for matching/cache keys, JSON validated after `json_decode()`,
  and `advanced-cache.php` (which runs before `wp_unslash()` exists). One real
  gap found (below).
- `error_log` family (15), `set_time_limit` (2), `load_plugin_textdomain` (1) —
  accepted per round 4 (debug/`WP_DEBUG`-gated paths; older-WP compat).

**Genuine gap (fixed):** `class-page-cache.php` read
`$_SERVER['REQUEST_METHOD']` **raw and unslashed** in `maybe_serve_cache()` and
`save_cache()`. WordPress slashes `$_SERVER` via `wp_magic_quotes()`, and the
same codebase already sanitizes that value in `Webp::send_webp_file()`
(`class-webp.php:3278`), so this violated AGENTS.md §4 (“sanitize on input”).
The path is admin-only debug metadata plus a `POST`/`HEAD` comparison, so the
risk was low, but the rule is mandatory.

### Fix applied in round 5 (verified by re-scan)

| # | Finding | File | Change | Result |
| --- | --- | --- | --- | --- |
| **R5-1** | Raw / unslashed `$_SERVER['REQUEST_METHOD']` read (`InputNotSanitized` + `MissingUnslash`) | `includes/class-page-cache.php` | Added a private `get_request_method()` helper (`isset` + `is_string` guard, then `strtoupper( sanitize_text_field( wp_unslash( … ) ) )`) and pointed both call sites at it. Behaviour is unchanged for real methods: the value is only compared to `POST`/`HEAD` and written into the admin debug trace | `InputNotSanitized` **20 → 16**; `MissingUnslash` **10 → 6** — 8 findings cleared at `class-page-cache.php:228,229,536,537` |

**Net after round 5: 176 findings — 16 ERROR, 160 WARNING** (184 − 8).

### Validation executed after the round-5 edit (2026-10-13)

| Check | Command | Result |
| --- | --- | --- |
| PHP lint | LocalWP `php -l` on all 11 PHP files | **11/11 clean** |
| JS syntax | `node --check assets/js/*.js` | **4/4 clean** (node v22.23.2) |
| I18n sniff | bundled `phpcs --standard=WordPress --sniffs=WordPress.WP.I18n --runtime-set text_domain fastlayer` (11 files) | **0 findings**, exit 0 |
| Full WPCS | bundled `phpcs --standard=WordPress` (11 files) | before **14,139 E / 1,323 W** → after **14,150 E / 1,323 W** (+11 E). The only sniffs that changed: `Generic.WhiteSpace.DisallowSpaceIndent` 13,189 → 13,208 (+19, the added, intentionally space-indented lines) and `ValidatedSanitizedInput.InputNotSanitized` 20 → 16 / `.MissingUnslash` 10 → 6. **No new rule category** |
| Plugin Check (before) | `wp plugin check fastlayer` | **184** (16 E / 168 W) — matches round 4 |
| Plugin Check (after) | `wp plugin check fastlayer` | **176** (16 E / 160 W) — the 8 fixed security rows cleared; the 16 intentional E are unchanged |
| Live site | `curl.exe -D - http://fastlayer.local/` (GET and HEAD) | HTTP **200** / **200** |
| Live plugin state | WP-CLI `eval-file` | plugin **active**; `defined('WP_CACHE')` = **true**; drop-in **present**; `enable_cache` = `'0'` |

The WPCS “after” figure was validated against an extraction of the pre-edit
backup zip run with the **same** PHPCS and file set, so the +11 delta is
attributable solely to R5-1 (the new helper’s space indentation, consistent with
the file’s intentional 4-space PHP style documented in AGENTS.md §3).

### Remaining findings after round 5 (all classified)

| # | Rule | Count | Classification |
| --- | --- | ---: | --- |
| 1 | `PrefixAllGlobals.NonPrefixedVariableFound` | 32 | **Intentional** — `advanced-cache.php`/`uninstall.php` run before/outside the bootstrap (see the round-3 note for this rule) |
| 2 | `DB.DirectDatabaseQuery.DirectQuery` + `.NoCaching` | 29 + 29 | **By design** — the DB cleanup tool must query directly; results are request-scoped |
| 3 | `Security.NonceVerification.Recommended` | 29 | **Read-only displays** — every state-changing handler verifies nonce + capability |
| 4 | `ValidatedSanitizedInput.InputNotSanitized` (16) / `MissingUnslash` (6) | 22 | **False positive / intentional** — `$_SERVER` used only for matching/cache keys, JSON validated after `json_decode()`, `advanced-cache.php` (pre-`wp_unslash()`) |
| 5 | `…file_system_operations_rmdir` (9), `.readfile` (3), `parse_url_parse_url` (2), `unlink_unlink` (1), `rename_rename` (1) | **16 E** | **Intentional, documented per-call** — see the per-call table above and R2-7…R2-10 |
| 6 | `error_log_error_log` (12), `error_log_set_error_handler` (1), `error_log_print_r` (1), `error_log_var_export` (1) | 15 | **Intentional** — error paths, `WP_DEBUG`-gated logging, `var_export` for the static config |
| 7 | `Squiz.PHP.DiscouragedFunctions.Discouraged` (`set_time_limit`) | 2 | **Intentional** — `function_exists()` + `@` guard before long WebP batch runs |
| 8 | `PluginCheck.CodeAnalysis…load_plugin_textdomainFound` | 1 | **Documented** — retained for older-WP compat; needs maintainer confirmation |
| 9 | `unexpected_markdown_file` (`AGENTS.md`) | 1 | **Packaging** — dev file; exclude from the production ZIP (`RELEASE-CHECKLIST.md §5`) |
| | **TOTAL** | **176** | 160 W + **16 E** (all native filesystem/`parse_url()`, row 5) |

**Suppression inventory (round 5):** unchanged from rounds 3–4 — the same
single-line/scoped `phpcs:ignore` comments with technical reasons. Round 5 added
**no** new suppression; it fixed the source instead (R5-1).

### Round 5 — continuation re-verification (2026-10-13)

A second, independent pass re-ran the whole toolchain and re-traced every
remaining finding to its call path. **No source file was changed**: the R5-1
helper is present and behaviourally identical to the pre-fix logic (the value is
only compared against `POST`/`HEAD` and written into the admin debug trace; a
missing method resolves to `''`, which matches neither).

The native-function exceptions were confirmed against core load order rather
than assumed. In this site's `wp-settings.php` the drop-in is included at
**line 100**, while `wp-includes/functions.php` — which defines
`wp_parse_url()`, `wp_unslash()` and `wp_delete_file()` — is only required at
**line 117**. `advanced-cache.php` therefore runs before any of those helpers
(and before WP_Filesystem is available), so its native
`parse_url()` / `readfile()` / `unlink()` calls are the only possible
implementation.

Independent re-run of the same commands against the same file set:

| Check | Result |
| --- | --- |
| PHP lint (11 PHP files) | **11/11 clean** — LocalWP PHP 7.4.30 |
| `node --check assets/js/*.js` | **4/4 clean** — node v22.23.2 |
| WP I18n sniff (`text_domain=fastlayer`) | **0 findings**, exit 0 |
| Full WPCS | **14,150 E / 1,323 W** — identical to the round-5 figure |
| Plugin Check | **176** (16 E / 160 W): `NonceVerification` 29, `InputNotSanitized` 16, `MissingUnslash` 6, `DirectQuery`/`NoCaching` 58, `PrefixAllGlobals` 32 |
| Live site | GET **200**, HEAD **200** |
| Live plugin state | **active**; `WP_CACHE` true; drop-in present; `enable_cache` = `'0'` |

Fresh backup for this pass:
`…\Temp\opencode\fastlayer-backups\fastlayer-plugin-r5-continue-20261013-103106.zip`.
The live page-cache serve/save paths were exercised on 2026-10-13 in the live
cache run (D-01…D-12, `tests/QA-CHECKLIST.md` R-55; D-04 blocked for lack of
WooCommerce) and are no longer blocked.

### Round 6 — R-57 cache debug-trace persistence fix (2026-10-13)

`Page_Cache::persist_debug_trace()` wrote the request trace through
`update_transient()`, which is **not a WordPress core function** (`update_option()`
and `set_transient()` are). The call was wrapped in
`function_exists( 'update_transient' )`, so it never fataled — but the guard was
always false, so the `fastlayer_cache_debug_trace` transient was **never written**
and the admin cache-debug panel (`class-admin.php` line 1986,
`get_transient( 'fastlayer_cache_debug_trace' )`) always rendered `n/a`.

Intended behaviour (confirmed from the read path and `docs/ARCHITECTURE.md`): keep
the most recent request trace in the `fastlayer_cache_debug_trace` transient with
a 300-second expiry.

| Item | Detail |
| --- | --- |
| Fix | `\set_transient( 'fastlayer_cache_debug_trace', $trace, 300 );` — replaces the dead `function_exists` / `update_transient` / log branch |
| Compatibility | `set_transient()` exists on every supported version (WP 6.0+, PHP 7.4); the `function_exists` guard is unnecessary and was removed |
| Argument order | `set_transient( $transient, $value, $expiration )` — verified against core |
| Sanitization | unchanged: the trace is written raw and escaped on output (`esc_html()`) in the admin panel |
| Read path | `class-admin.php:1986`; `delete_transient()` on purge / preload / css-js clear; removed on uninstall |
| Scope | only `includes/class-page-cache.php`; no other behaviour, setting, version or folder touched |
| Backup | `…\Temp\opencode\fastlayer-backups\fastlayer-r57-20261013-121152.zip` (sha256 `13a5404a…5f4b`) |

Exact diff:

```diff
     private function persist_debug_trace() {
         $trace = $this->debug_trace;
         $trace['updated_at'] = \time();

-        if ( function_exists( 'update_transient' ) ) {
-            \update_transient( 'fastlayer_cache_debug_trace', $trace, 300 );
-            return;
-        }
-
-        \fastlayer_debug_log( 'WP FastLayer: update_transient() unavailable while persisting cache debug trace.' );
+        \set_transient( 'fastlayer_cache_debug_trace', $trace, 300 );
     }
```

Focused regression check added: `tests/test-r57-debug-trace-transient.php`, run
with `wp eval-file`. It stores a sentinel trace through the private method, reads
it back with `get_transient()`, checks every field plus an integer `updated_at`,
confirms the ~300 s timeout option, then deletes the transient. Page caching does
not need to be enabled.

| Check | Result |
| --- | --- |
| Pre-fix body (A/B revert) | **FAIL** (exit 1) — `get_transient()` returned nothing; timeout option missing |
| Fixed body | **PASS** (exit 0) |
| Live request (`enable_cache='0'`) | transient populated after one anonymous `GET /` → `skip_reason = cache_disabled, buffer_not_started` |

Validation executed after the fix (2026-10-13):

| Check | Result |
| --- | --- |
| PHP lint, 12 PHP files | **12/12 clean** |
| `wp eval-file tests/test-r57-debug-trace-transient.php` | **PASS** (exit 0); pre-fix A/B **FAIL** (exit 1) |
| I18n sniff (`text_domain=fastlayer`) | **0 findings**, exit 0 |
| Full WPCS, 12 PHP files | **14,178 E / 1,323 W** (was 14,150 E / 1,323 W on 11 files; +28 E, all `Tabs must be used…` space-indent, matching the plugin's 4-space house style; Warnings unchanged) |
| Plugin Check | **176** (16 E / 160 W) — unchanged from the round-5 post-R5-1 figure; **0** findings in the new test file |
| Live site | GET **200**; `enable_cache` = `'0'`; cache dir has 0 `.html` |

---

## Part B-implementation-6 — Pre-fix verification snapshot (round 7, 2026-10-13)

This pass was the final pre-release documentation/verification pass **before** the
AlternativeFunctions code fix. **No source code was changed in this pass** and the
version stays `1.1.0`. Every figure below was re-executed or re-parsed in this
workspace on 2026-10-13.

> **Superseded (2026-10-13):** the `16 ERROR` result below is the state *before*
> the WP_Filesystem code pass. The current result is **0 ERROR / 160 WARNING** —
> see Part B-implementation-7.

### Environment (re-verified)

| Item | Value |
| --- | --- |
| Site | `http://fastlayer.local/` — GET **200**, HEAD **200** |
| Plugin path | `…/wp-content/plugins/fastlayer` |
| Plugin state | `fastlayer 1.1.0` **active**; `plugin-check 2.1.0` active; `advanced-cache.php` drop-in present; `WP_CACHE` = true |
| WordPress | 7.1.3 |
| PHP | 7.4.30 (live site) and 8.2.29 (cross-check) |
| WP-CLI | 2.12.0 |
| Node | v22.23.2 |

### Plugin Check before the fix: 176 (16 ERROR / 160 WARNING)

Re-parsed from the saved scan (`%TEMP%\opencode\pcp-r57-final.csv`) taken **before**
the WP_Filesystem fix. The 16 ERROR rows were all `WordPress.WP.AlternativeFunctions.*`.
The table below is retained as the fix rationale: nine of the sixteen (`rmdir`) were
subsequently migrated to WP_Filesystem and the remaining seven carry documented
`phpcs:ignore` exceptions (see Part B-implementation-7).

| # | Rule | File:line | Contextual reason (why it is not a defect) |
| --- | --- | --- | --- |
| 1 | `parse_url_parse_url` | `advanced-cache.php:45` | `fastlayer_host_from_url()` runs in the drop-in **before WordPress loads**; `wp_parse_url()` is not defined yet. |
| 2 | `parse_url_parse_url` | `advanced-cache.php:112` | `fastlayer_strip_url_domain()` — same load-order reason; mirrors `Page_Cache::strip_url_domain()` so both cache paths agree. |
| 3 | `unlink_unlink` | `advanced-cache.php:330` | Deletes an expired cache file pre-WP (`wp_delete_file()` undefined); `@`-guarded and path pre-validated by `fastlayer_is_safe_path()`. |
| 4 | `file_system_operations_readfile` | `advanced-cache.php:339` | Streams an already-validated cache file; `wp_readfile()` is not available in the drop-in. |
| 5 | `file_system_operations_rmdir` | `class-admin-bar.php:317` | Empties/removes cache subdirectories; WordPress has no `wp_rmdir()`. |
| 6 | `file_system_operations_rmdir` | `class-admin-bar.php:326` | Removes the now-empty cache root; no `wp_rmdir()`. |
| 7 | `file_system_operations_rmdir` | `class-admin.php:1538` | Removes empty minified-asset dirs after `wp_delete_file()` emptied them; no `wp_rmdir()`. |
| 8 | `rename_rename` | `class-bootstrap.php:596` | Atomic stage-and-`rename()` write of `wp-config.php` (AGENTS.md §5(2)); `rename()` is **required** for atomicity. |
| 9 | `file_system_operations_rmdir` | `class-bootstrap.php:772` | Removes the empty legacy `logs/` dir; no `wp_rmdir()`. |
| 10 | `file_system_operations_readfile` | `class-page-cache.php:494` | `serve_cache()` streams the validated cache file; `readfile()` is the correct streaming primitive. |
| 11 | `file_system_operations_readfile` | `class-webp.php:3323` | `send_webp_file()` streams the WebP binary; `readfile()` is the correct streaming primitive. |
| 12–16 | `file_system_operations_rmdir` | `uninstall.php:62,76,90,137,147` | Uninstall removes emptied plugin-generated directories; no `wp_rmdir()` exists. |

*(Line `class-page-cache.php:494` reflects the post-R5-1 source; it was `493`
before the helper insertion.)*

Remaining warnings (160) and their contextual reasons:

| Rule | Count | Reason it is accepted |
| --- | ---: | --- |
| `PrefixAllGlobals.NonPrefixedVariableFound` | 32 | `advanced-cache.php` (8) + `uninstall.php` (24) run before/outside the bootstrap, so their globals cannot be namespaced. |
| `Security.NonceVerification.Recommended` | 29 | Display-only / read-only `$_GET` flags and `$_SERVER` reads. Every state-changing `admin_post_*` / `wp_ajax_*` handler verifies a nonce **and** capability. |
| `DB.DirectDatabaseQuery.DirectQuery` + `.NoCaching` | 29 + 29 | The DB cleanup tool must query directly; results are request-scoped and the cleanup DELETEs cannot be cached. Queries contain no user input. |
| `ValidatedSanitizedInput.InputNotSanitized` (16) / `MissingUnslash` (6) | 22 | `$_SERVER` used only for matching/cache keys; JSON validated after `json_decode()`; `advanced-cache.php` runs before `wp_unslash()` exists. |
| `error_log_error_log` (12), `.set_error_handler` (1), `.print_r` (1), `.var_export` (1) | 15 | `WP_DEBUG`-gated error paths; `var_export` for the static generated config. |
| `Squiz.PHP.DiscouragedFunctions.Discouraged` (`set_time_limit`) | 2 | `function_exists()` + `@` guard before long WebP batch runs. |
| `load_plugin_textdomainFound` | 1 | Retained for older-WP compatibility; removal needs maintainer confirmation (AGENTS.md §6). |
| `unexpected_markdown_file` (`AGENTS.md`) | 1 | Development-only file **excluded** from the production ZIP (RELEASE-CHECKLIST §5). |
| **TOTAL** | **160 W + 16 E = 176 (pre-fix)** | |

No `PreparedSQL.NotPrepared` and no `EscapeOutput.OutputNotEscaped` findings
remain (both cleared in earlier rounds).

### R-57 regression re-verified on both PHP branches

The R-57 fix (`set_transient( 'fastlayer_cache_debug_trace', $trace, 300 )` in
`includes/class-page-cache.php`, round 6) was re-executed this pass:

| Check | PHP 7.4.30 | PHP 8.2.29 |
| --- | --- | --- |
| `php -l` (12 files) | 12/12 clean | 12/12 clean |
| `wp eval-file tests/test-r57-debug-trace-transient.php` | **PASS** (exit 0) | **PASS** (exit 0) |
| Pre-fix A/B body | FAIL (exit 1) — trace not stored | (same code path) |

### Additional executed checks (round 7)

| Check | Command | Result |
| --- | --- | --- |
| JS syntax | `node --check assets/js/*.js` | 4/4 clean (node v22.23.2) |
| I18n sniff | bundled PHPCS `WordPress.WP.I18n`, `text_domain=fastlayer` | 0 findings |
| Readme validator | official WordPress.org Readme Validator | No errors, no warnings; sole note “No donate link was found” |
| WP 6.0 function compatibility | `wp plugin check fastlayer --checks=wp_functions_compatibility` | “No errors found” |
| Slug availability | `api.wordpress.org/plugins/info/1.2` for `fastlayer` | 404 “Plugin not found” — slug is free |
| Contributor profile | `profiles.wordpress.org/kanhajatthap` | HTTP 200 — exists |

### Production manifest

The production ZIP contains **exactly 18 files** (no `LICENSE`, no
`assets/images/`). The full list — with size and `sha256` prefix — is recorded in
`RELEASE-CHECKLIST.md` §5. `AGENTS.md`, `docs/` and `tests/` are **excluded**.

### Remaining limitations (round 7)

- **Blocked:** WordPress 6.0 runtime (no disposable env — only the static
  `wp_functions_compatibility` check covers it).
- **Blocked:** Apache / LiteSpeed (only nginx is installed).
- **Blocked:** fresh install, upgrade-from-1.0.0, and multisite (no disposable site).
- **Blocked:** WooCommerce cart/session flows (D-04) — WooCommerce not installed.
- **Not done (by instruction):** no release ZIP was created and nothing was
  uploaded or submitted to WordPress.org. Version stayed `1.1.0`.

---

## Part B-implementation-7 — AlternativeFunctions / WP_Filesystem resolution (round 7 code pass, 2026-10-13)

The final pre-release code pass in this workspace. It clears the last Plugin Check
**ERRORs** (the 16 `WordPress.WP.AlternativeFunctions.*` rows) without breaking the
early cache drop-in, the atomic `wp-config.php` writer, or uninstall cleanup. The
version stays `1.1.0`; no release ZIP was built and nothing was uploaded.

### What changed

Nine of the sixteen findings were genuine and were migrated to the WordPress
filesystem API; the seven that cannot safely use a WordPress helper keep a **narrow,
line-scoped `phpcs:ignore`** with the technical reason on the same line.

| # | Finding(s) | File | Change | Type |
| --- | --- | --- | --- | --- |
| R7-1 | `file_system_operations_rmdir` ×2 | `includes/class-admin-bar.php` | `@rmdir()` → `Bootstrap::delete_empty_directory()` | Migrated |
| R7-2 | `file_system_operations_rmdir` ×1 | `includes/class-admin.php` | `@rmdir()` → `Bootstrap::delete_empty_directory()` | Migrated |
| R7-3 | `file_system_operations_rmdir` ×1 | `includes/class-bootstrap.php` | legacy `logs/` removal → `self::delete_empty_directory()` | Migrated |
| R7-4 | `file_system_operations_rmdir` ×5 | `uninstall.php` | five `@rmdir()` → `$wp_filesystem->rmdir()` after one `WP_Filesystem()` init | Migrated |
| R7-5 | `parse_url` ×2, `unlink` ×1, `readfile` ×1 | `advanced-cache.php` | documented `phpcs:ignore` (pre-WordPress drop-in) | Exception |
| R7-6 | `rename` ×1 | `includes/class-bootstrap.php` | documented `phpcs:ignore` (atomicity, AGENTS.md §5(2)) | Exception |
| R7-7 | `readfile` ×1 each | `includes/class-page-cache.php`, `includes/class-webp.php` | documented `phpcs:ignore` (streaming; WP_Filesystem cannot stream a response) | Exception |

### The new helper and the fail-closed contract

`includes/class-bootstrap.php` gains two methods:

- `private static function get_filesystem()` — returns an initialised
  `WP_Filesystem_Base` instance or `false`, cached per request (so the write test
  runs once). It reuses an existing `$wp_filesystem`, otherwise `require_once`s
  `wp-admin/includes/file.php` and calls `WP_Filesystem()`.
- `public static function delete_empty_directory( $directory )` — normalises the
  path, refuses anything that is not an existing directory, and calls
  `$filesystem->rmdir()`.

**Fail-closed:** when WP_Filesystem cannot be initialised (for example the
`get_filesystem_method()` is not `direct`), both `delete_empty_directory()` and the
`uninstall.php` removals **leave the directory in place** instead of falling back to
a raw PHP `rmdir()`. `rmdir()` semantics are preserved: a non-empty directory and a
file path are both refused. This workspace uses the `direct` filesystem method, so
removals proceed normally.

`uninstall.php` follows the same contract: it `require_once`s
`wp-admin/includes/file.php`, calls `WP_Filesystem()` **once**, and each of the five
plugin-generated directories is removed with `$wp_filesystem->rmdir()` only when the
filesystem was initialised; otherwise the (now empty) directory is left in place. The
pre-existing `wp_delete_file()` calls that emptied the directories are unchanged.

### Why the seven exceptions are not defects

- `advanced-cache.php` is the cache drop-in. WordPress includes it at
  `wp-settings.php` line 100, before `wp-includes/functions.php` (line 117) defines
  `wp_parse_url()`, `wp_unslash()` and `wp_delete_file()`, and before WP_Filesystem
  exists. Its `parse_url()` / `unlink()` / `readfile()` are the only implementation
  available; the file paths are pre-validated by `fastlayer_is_safe_path()`.
- `rename()` in the `wp-config.php` atomic writer must stay a real `rename()`:
  `WP_Filesystem::move()` can fall back to a non-atomic copy+delete that could
  truncate `wp-config.php`. The staged file is verified before the move (AGENTS.md §5).
- The two `readfile()` calls stream an already-validated cached page / WebP binary
  and the request ends immediately after; WP_Filesystem has no streaming primitive.

None of the seven is suppressed with a blanket ignore: each `phpcs:ignore` names the
exact sniff and states the reason on the same line.

### Validation executed after this pass (2026-10-13)

| Check | Command | Result |
| --- | --- | --- |
| PHP lint | `php -l` on all 13 PHP files (11 plugin + 2 regression scripts) on LocalWP PHP **7.4.30** *and* **8.2.29** | **13/13 clean** on both |
| JS syntax | `node --check assets/js/*.js` | **4/4 clean** (node v22.23.2) |
| WP I18n sniff | bundled `phpcs --sniffs=WordPress.WP.I18n --runtime-set text_domain fastlayer` (13 files) | **0 findings**, exit 0 |
| Full WPCS | bundled `phpcs --standard=WordPress` (13 files) | **14,262 E / 1,298 W** |
| Filesystem regression | `wp eval-file tests/test-filesystem-rmdir.php` | **PASS** (exit 0) on PHP 7.4.30 *and* 8.2.29 |
| R-57 regression | `wp eval-file tests/test-r57-debug-trace-transient.php` | **PASS** (exit 0) on PHP 7.4.30 *and* 8.2.29 |
| Plugin Check | `wp plugin check fastlayer` | **0 E / 160 W** (was 16 E / 160 W) |
| Live site | `curl.exe` GET + HEAD `http://fastlayer.local/` | HTTP **200** / **200** |

**Plugin Check detail.** The fresh export contains **160 WARNING rows and 0 ERROR
rows**. Source scan: `WordPress.WP.AlternativeFunctions.*` = **0**. The
non-`AlternativeFunctions` finding set is identical
(file:line:column:type:code) to the pre-fix `%TEMP%\opencode\pcp-round7fs.csv` export
for all 160 warnings; the 160 remaining warnings are unchanged and accepted
(Part B-implementation-6).

Two environment notes, recorded honestly:

- The `enqueued_styles_size` runtime check (an `Abstract_Runtime_Check` that
  simulates a front-end request) did **not** complete within 300 s in this
  environment. The full scan was therefore re-run with
  `--exclude-checks=enqueued_styles_size`; that export is identical
  (file:line:column:type:code) to the earlier **complete** `pcp-round7fs.csv` for
  every finding, and neither export contains an `enqueued*` finding, so the
  exclusion does not change the result.
- WordPress 6.0 runtime, Apache/LiteSpeed, fresh install / upgrade / multisite and
  WooCommerce flows remain **Blocked** (no disposable environment) — see
  Part B-implementation-6.

### WPCS delta introduced by this pass

Comparing the pre-change file set with the post-change set using the same bundled
WPCS (13 files): `Generic.WhiteSpace.DisallowSpaceIndent` **+69** (the added
comment/helper lines use the project's documented 4-space PHP indent, AGENTS.md §3),
`WordPress.PHP.NoSilencedErrors` **−9** (nine `@rmdir()` suppressions removed), and
the native `WordPress.WP.AlternativeFunctions.*` sniffs fall to **0** (`rmdir` −9,
`readfile` −3, `parse_url` −2, `rename` −1, `unlink` −1). **No new rule category**
was introduced.

### Production manifest

Unchanged: the production ZIP still contains **exactly 18 files**. All changes are
inside the seven existing, already-listed files, so `RELEASE-CHECKLIST.md` §5 was
re-derived (byte size + `sha256`) and the count stays 18. `tests/` (now two
regression scripts) and `docs/` remain excluded.

---

## Part C — Plugin Check appendix (grouped by rule)

Source: `fastlayer-wp-fastlayer-php-20261007-050544.csv` — 275 rows
(**171 WARNING**, **104 ERROR**). Counts are exact from the export; per-rule
severity is shown where all rows of a rule share one type.

| Rule | Count | Severity |
| --- | ---: | --- |
| `WordPress.Security.NonceVerification.Recommended` | 29 | WARNING |
| `WordPress.WP.I18n.MissingTranslatorsComment` | 29 | WARNING |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | 28 | WARNING |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | 28 | WARNING |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` | 23 | WARNING |
| `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | 23 | ERROR |
| `WordPress.WP.AlternativeFunctions.unlink_unlink` | 22 | WARNING |
| `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` | 13 | ERROR |
| `WordPress.PHP.DevelopmentFunctions.error_log_error_log` | 12 | WARNING |
| `WordPress.WP.AlternativeFunctions.file_system_operations_is_writable` | 9 | WARNING |
| `missing_direct_file_access_protection` | 8 | ERROR |
| `WordPress.WP.AlternativeFunctions.file_system_operations_rmdir` | 8 | WARNING |
| `WordPress.WP.I18n.UnorderedPlaceholdersText` | 8 | WARNING |
| `WordPress.DB.PreparedSQL.NotPrepared` | 7 | ERROR |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 5 | ERROR |
| `WordPress.WP.AlternativeFunctions.file_system_operations_readfile` | 3 | WARNING |
| `Internal.LineEndings.Mixed` | 3 | WARNING |
| `WordPress.WP.AlternativeFunctions.parse_url_parse_url` | 2 | WARNING |
| `Squiz.PHP.DiscouragedFunctions.Discouraged` | 2 | WARNING |
| `WordPress.PHP.DevelopmentFunctions.error_log_print_r` | 2 | WARNING |
| `WordPress.WP.EnqueuedResources.NonEnqueuedScript` | 1 | WARNING |
| `readme_parser_warnings_too_many_tags` | 1 | WARNING |
| `readme_parser_warnings_no_short_description_present` | 1 | WARNING |
| `plugin_header_nonexistent_domain_path` | 1 | WARNING |
| `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` | 1 | WARNING |
| `WordPress.PHP.DevelopmentFunctions.error_log_var_export` | 1 | WARNING |
| `WordPress.DateTime.RestrictedFunctions.date_date` | 1 | WARNING |
| `WordPress.Security.EscapeOutput.OutputNotEscaped` | 1 | ERROR |
| `WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet` | 1 | WARNING |
| `WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler` | 1 | WARNING |
| `WordPress.WP.AlternativeFunctions.rename_rename` | 1 | WARNING |
| **TOTAL** | **275** | 171 W + 104 E |

*(Severity column reflects the export; the 104 ERROR rows are made up of the
rules where the export marks ERROR — primarily InputNotSanitized, MissingUnslash,
NotPrepared, UnescapedDBParameter, missing_direct_file_access_protection and
OutputNotEscaped.)*

---

## Part D — Tests actually executed

### Automated (this workspace)

> **Latest result (round-7 code pass, 2026-10-13):** `php -l` **13/13** clean on
> PHP 7.4.30 *and* 8.2.29; `node --check` 4/4; full WPCS 14,262 E / 1,298 W;
> Plugin Check **0 E / 160 W**. The rows below are the dated history that led
> here — the earlier `11/11` and `12/12` lint counts reflect the smaller file set
> at the time (the 13th file is the new filesystem regression test).

| Test | Command | Result |
| --- | --- | --- |
| JS syntax, all four files | `node --check assets/js/*.js` | **PASS** (node v22.23.2) — 4/4 exit 0 |
| PHP lint | `php -l` (LocalWP PHP 7.4.30) | **PASS (2026-10-12)** — 11/11 PHP files clean |
| PHPCS / WPCS (bundled) | `vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.WP.I18n --runtime-set text_domain fastlayer` (all 11 files) | **PASS** — 0 findings, exit 0 |
| Plugin Check re-scan (round 3) | `wp plugin check FastLayer` (WP-CLI 2.12.0 + plugin-check 2.1.0, live local site WP 7.1.3) | **PASS (2026-10-12)** — 679 → 625 findings; see Part B-implementation-3 |
| Plugin Check re-scan (round 4) | `wp plugin check fastlayer` (post-rename; pre- and post-fix) | **PASS (2026-10-12)** — 625 → 185 (rename cleared 440 text-domain rows) → **184** after R4-2; see Part B-implementation-4 |
| Full WPCS (round 5) | `vendor/bin/phpcs --standard=WordPress` (all 11 files) | **Executed (2026-10-13)** — 14,139 E / 1,323 W → **14,150 E / 1,323 W** (+11 from the added lines); no new rule category; see Part B-implementation-5 |
| Plugin Check re-scan (round 5) | `wp plugin check fastlayer` (pre- and post-R5-1) | **PASS (2026-10-13)** — **184** (16 E / 168 W) → **176** (16 E / 160 W); 8 security rows cleared; see Part B-implementation-5 |
| Debug-trace regression check (round 6) | `wp eval-file tests/test-r57-debug-trace-transient.php` (LocalWP PHP 7.4.30 + WP-CLI 2.12.0) | **PASS (2026-10-13)** — exit 0; pre-fix A/B body exit 1 (trace not stored); Plugin Check unchanged at **176**; see Part B-implementation-5 "Round 6" |
| PHP lint, PHP 8.2.29 (round 7) | `php -l` (LocalWP PHP 8.2.29) on 12 files | **PASS (2026-10-13)** — 12/12 clean |
| R-57 regression, PHP 8.2.29 (round 7) | `wp eval-file tests/test-r57-debug-trace-transient.php` (PHP 8.2.29) | **PASS (2026-10-13)** — exit 0 |
| Readme validator (round 7) | official WordPress.org Readme Validator | **PASS (2026-10-13)** — no errors, no warnings; sole note "No donate link was found" |
| WP 6.0 function compatibility (round 7) | `wp plugin check fastlayer --checks=wp_functions_compatibility` | **PASS (2026-10-13)** — "No errors found" |
| PHP lint (round 7 code pass) | `php -l` (LocalWP PHP 7.4.30 and 8.2.29) on 13 files | **PASS (2026-10-13)** — 13/13 clean on **both** branches |
| Filesystem regression (round 7 code pass) | `wp eval-file tests/test-filesystem-rmdir.php` (PHP 7.4.30 and 8.2.29) | **PASS (2026-10-13)** — exit 0 on both; empty dir removed, non-empty dir/file path refused; see Part B-implementation-7 |
| R-57 regression re-run (round 7 code pass) | `wp eval-file tests/test-r57-debug-trace-transient.php` (PHP 7.4.30 and 8.2.29) | **PASS (2026-10-13)** — exit 0 on both |
| I18n sniff (round 7 code pass) | bundled `phpcs --sniffs=WordPress.WP.I18n --runtime-set text_domain fastlayer` (13 files) | **PASS (2026-10-13)** — 0 findings, exit 0 |
| Full WPCS (round 7 code pass) | bundled `phpcs --standard=WordPress` (13 files) | **Executed (2026-10-13)** — **14,262 E / 1,298 W**; delta vs pre-change: +69 space-indent, −9 silenced-errors, AlternativeFunctions sniffs → 0; no new rule category |
| Plugin Check re-scan (round 7 code pass) | `wp plugin check fastlayer` (WP-CLI 2.12.0 + plugin-check 2.1.0, live local site WP 7.1.3) | **PASS (2026-10-13)** — **176** (16 E / 160 W) → **160** (0 E / 160 W); the 16 `AlternativeFunctions` ERRORs cleared; warnings unchanged; see Part B-implementation-7 |
| Live smoke (round 7 code pass) | `curl.exe` GET + HEAD `http://fastlayer.local/` | **PASS (2026-10-13)** — HTTP 200 / 200 |

### Manual (maintainer environment)

| Test | Environment | Result |
| --- | --- | --- |
| PHP compatibility | PHP 7.4.30 | **PASS** — manual compatibility testing completed (maintainer-reported, 2026-10-12) |
| PHP compatibility below 7.4 | — | **NOT TESTED** — not available; no reviewer requirement |

> PHP lint, PHPCS, full WPCS and Plugin Check were **executed in this workspace
> on 2026-10-12 and again on 2026-10-13** (LocalWP PHP 7.4.30 + the
> plugin-check-bundled PHPCS + WP-CLI 2.12.0 against the live local site) — see
> Part B-implementation-3, -4, -5 and the round-6 fix. The `WP_CACHE`
> deactivate/reactivate cycle was also exercised on the live site
> (Part B-implementation-4), and the live page-cache serve/save flows were
> executed on 2026-10-13 (D-01…D-12; R-55, with D-04 blocked because WooCommerce
> is not installed). PHPUnit is unavailable in this environment, so the focused
> regression check is a `wp eval-file` script (`tests/test-r57-debug-trace-transient.php`).
> The PHP 7.4.30 compatibility row above is a maintainer-reported **manual** test,
> not an automated one.
>
> **Round 7 (2026-10-13):** the same lint + R-57 regression were additionally
> executed on **PHP 8.2.29** (both PASS), the readme was validated with the
> official WordPress.org Readme Validator (no errors/warnings), and the static
> `wp_functions_compatibility` check passed. All figures are collected in
> Part B-implementation-6. The follow-on AlternativeFunctions / WP_Filesystem
> **code pass** re-ran the whole toolchain over the 13-file set (lint, JS, I18n
> sniff, full WPCS, both `wp eval-file` regression tests, Plugin Check, live
> GET/HEAD) — see Part B-implementation-7.

---

## Part E — Recommended next steps

1. ~~**Re-run Plugin Check** against the current build to retire stale findings
   (B10, B18–B21), confirm the C-1 / C-2 / WebP edits cleared the intended rows,
   and re-baseline the counts. **Blocked** — no PHP/WP-CLI in this environment.~~
   **Done (2026-10-12)** — executed via WP-CLI 2.12.0 + plugin-check 2.1.0 on the
   live local site: 679 → 625 findings (Part B-implementation-3), and after the
   lowercase folder rename **625 → 185 → 184** (Part B-implementation-4). The
   440 text-domain rows are gone; no stale B10/B18–B21 rows remain.
2. ~~Fix the SQL findings first (**B6, B7**).~~ **Partly done** — the WebP
   prepare call was converted to a direct `$wpdb->prepare()` and the
   `OPTIMIZE TABLE` identifier was hardened (see Part B-implementation). The
   remaining static-query warnings are false positives; decide with the
   maintainer whether to leave them visible or refactor.
3. ~~Work through nonce/sanitize/unslash findings (**B1, B4, B5**).~~ **Done** —
   re-verified as false positives / accepted read-only patterns; only C-2
   required a code change.
4. Investigate **A9** (recurring `WP_CACHE` warning) on a live site; capture the
   exact sequence and `wp-config.php` state. Then fix and add a regression test.
   **Partly done (round 4):** the deactivate/reactivate cycle was executed on the
   live site — the drop-in and the marked `WP_CACHE` line are cleanly
   removed/restored with no duplicate, and `defined('WP_CACHE')` is `true` on the
   next request. The **non-writable** `wp-config.php` branch (which warns every
   load) is still **open**. See Part B-implementation-4.
5. Add translator comments (**B2**) and keep the `.pot` in sync. **Still open.**
6. ~~Normalise line endings in the three mixed files (**B16**).~~ **Done (round 3).** Re-verified 2026-10-13: the three files are LF-only and the current Plugin Check export has no `LineEndings.Mixed` row.
7. Only after that, prepare a release per `docs/RELEASE-CHECKLIST.md`.
