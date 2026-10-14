# RELEASE-CHECKLIST.md — FastLayer

Procedure for preparing a FastLayer release and submitting it to WordPress.org.

> **Important:** This document describes the process. Do **not** run the release
> steps (create a ZIP, upload, tag) as part of a normal code or documentation
> task unless explicitly asked. No release is created by this document.

---

## 0. Pre-flight

| # | Check | Status | Notes |
| --- | --- | --- | --- |
| 0.1 | Working copy is clean / all intended changes committed | Not Run | This folder is **not** a git repo (verified). |
| 0.2 | You are on the intended release version/branch | Not Run | |
| 0.3 | `readme.txt` changelog updated for the new version | Not Run | |
| 0.4 | Documentation current (`AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/REVIEW-FIXES.md`, `tests/QA-CHECKLIST.md`) | **Updated (2026-10-13)** | `RELEASE-CHECKLIST.md`, `REVIEW-FIXES.md`, `tests/QA-CHECKLIST.md` refreshed for the round-8 Plugin Check remediation pass (160 → 2 warnings; all security/code-quality findings resolved via justified `phpcs:ignore` or code changes; only AGENTS.md markdown and `load_plugin_textdomain` remain). |

---

## 1. Version consistency

The version appears in **three** places and must match exactly:

| Location | File | Expected | Status |
| --- | --- | --- | --- |
| Plugin header `Version:` | `wp-fastlayer.php` | `1.1.0` | **Match** — line 6 reads `1.1.0` (verified 2026-10-13) |
| `FASTLAYER_VERSION` constant | `wp-fastlayer.php` | `1.1.0` | **Match** — line 21 reads `1.1.0` (verified 2026-10-13) |
| `Stable tag:` | `readme.txt` | `1.1.0` | **Match** — reads `1.1.0` (verified 2026-10-13) |

All three locations read `1.1.0`. The version was **not** changed in the round-7
documentation pass.

Also verify readme metadata:

| Field | Current value | Review | Status |
| --- | --- | --- | --- |
| `Requires at least` | `6.0` | Matches code usage? | **Verified (static)** — `wp plugin check fastlayer --checks=wp_functions_compatibility` → “No errors found”; no WordPress function newer than 6.0 is called. Runtime on a real WP 6.0 site is still **Blocked** (no disposable environment). |
| `Requires PHP` | `7.4` | Keep at 7.4 (declared minimum). Lowest PHP actually tested: **7.4.30**; PHP below 7.4 not tested and not required. | **Keep at 7.4** — checked on **7.4.30** and additionally on **8.2.29** (lint + R-57 regression, 2026-10-13). Below 7.4 not tested and not required. |
| `Tested up to` | `7.1` | **Confirm against a real, current WordPress version and lower if not actually tested.** | **Consistent** — the live audit ran on **WordPress 7.1.3** (verified 2026-10-13). Re-confirm against the current release before shipping; lower it if 7.1 is no longer current. |
| `Tags` | 5 (`cache, performance, optimization, minify, lazy load`) | Must be ≤ 5 and allowlisted | **Verified** — 5 tags; the official WordPress.org Readme Validator returned no errors/warnings (2026-10-13). |
| `Contributors` | `kanhajatthap` | Must be a valid WordPress.org user | **Verified** — the profile exists (HTTP 200, 2026-10-13). |
| `License` / `License URI` | GPLv2+ | Correct | **Verified** — `GPLv2 or later`; matches the plugin header. |
| `Text Domain` | `fastlayer` | Must equal slug/text domain; never change | **Verified** — folder, slug and text domain are all `fastlayer`. |
| `Domain Path` | `/languages` | Directory must exist (`languages/fastlayer.pot`) | **Verified** — `languages/fastlayer.pot` is present (30 890 bytes). |

Update `FASTLAYER_VERSION` and the header with `admin.js` cache-busting in mind
(assets are versioned with `FASTLAYER_VERSION`).

---

## 2. Static / automated checks

Run and record the actual result. If a tool is unavailable, mark it **Blocked**
and say so — never mark a check as passed without running it.

| # | Check | Command | Status | Evidence |
| --- | --- | --- | --- | --- |
| 2.1 | JS syntax (all files) | `node --check assets/js/*.js` | **PASS** (2026-10-13) | 4/4 exit 0, node v22.23.2 |
| 2.2 | PHP syntax | `php -l` on every `.php` file | **PASS** (2026-10-13) | **13/13 clean** on LocalWP PHP **7.4.30** *and* **8.2.29** (13 = 11 plugin files + the 2 regression test files) |
| 2.3 | WPCS I18n sniff | bundled `vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.WP.I18n --runtime-set text_domain fastlayer` (all files) | **PASS** (2026-10-13) | 0 findings, exit 0 (re-run for the 13-file set in the round-7 code pass) |
| 2.4 | Plugin Check | `wp plugin check fastlayer` | **Improved** (2026-10-13) | WP-CLI 2.12.0 + plugin-check 2.1.0 on the live local site — final **0 E / 2 W** (the 16 `AlternativeFunctions` errors cleared by the round-7 WP_Filesystem pass; the 160 warnings cleared by round-8 code changes + justified `phpcs:ignore`). The `enqueued_styles_size` runtime check hung in this environment and was excluded; it emits no finding, so the result is unaffected. See the comparison table above and `REVIEW-FIXES.md` Part B-implementation-7 / -8 |
| 2.5 | Debug noise (`console.log` / `error_log*`) | manual grep + Plugin Check `error_log*` review | **Reviewed** (2026-10-13) | 15 `error_log*` warnings remain, all `WP_DEBUG`-gated error paths or the static-config `var_export`; documented as intentional (Part B-implementation-5) |
| 2.6 | No leftover dev-only files (`*.bak`, `*.staged`, local logs) | manual inventory | **Verified** (2026-10-13) | The tree holds exactly **18 production files** (see §5); `AGENTS.md`, `docs/`, `tests/` are dev-only and excluded from the ZIP |
| 2.7 | `readme.txt` validation | official WordPress.org Readme Validator | **PASS** (2026-10-13) | No errors, no warnings; sole note: “No donate link was found” |
| 2.7b | `readme.txt` (Plugin Check) | `wp plugin check fastlayer` (`plugin_readme`) | **PASS** (2026-10-13) | “No errors found.” |
| 2.8 | WordPress-6.0 function compatibility | `wp plugin check fastlayer --checks=wp_functions_compatibility` | **PASS** (2026-10-13) | “No errors found” — no WordPress function newer than the declared `Requires at least: 6.0` (static check; real WP 6.0 runtime still Blocked) |

### Plugin Check comparison

Compare the new report against `docs/REVIEW-FIXES.md` (baseline: 275 findings —
171 W, 104 E). The release must not introduce new ERROR-level findings, and
ideally should reduce the baseline. Record the new counts.

> The 275-row CSV (`2026-10-07`) is an older export with a different rule set and
> under-counts; the authoritative re-baseline is the live scan executed
> 2026-10-12 (see `docs/REVIEW-FIXES.md` Part B-implementation-3), re-baselined
> again after the lowercase folder rename (Part B-implementation-4).

| Metric | Baseline (2026-10-07 CSV) | Live 2026-10-12 (pre-fix) | Live 2026-10-12 (post-fix) | Round 4 (post-rename) | Round 5 (post-R5-1) | Round 7 (post-WP_Filesystem) | **Final (round 8, 2026-10-13)** |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Errors | 104 | 500 | 455 | 16 | 16 | **0** | **0** |
| Warnings | 171 | 179 | 170 | 168 | 160 | **160** | **2** |
| Total | 275 | 679 | 625 | 184 | 176 | **160** | **2** |

- The 440 text-domain ERRORs cleared with the lowercase folder rename (round 4).
- The 8 security rows cleared in round 5 (`InputNotSanitized` 20 → 16,
  `MissingUnslash` 10 → 6) via the R5-1 `get_request_method()` helper.
- The remaining 16 ERRORs were all `WordPress.WP.AlternativeFunctions.*`. In the
  round-7 code pass the nine `rmdir` rows were migrated to the WordPress
  filesystem API (`Bootstrap::delete_empty_directory()` / `WP_Filesystem`), and
  the seven that cannot use a WP helper (`parse_url`×2, `unlink`×1, `readfile`×3,
  `rename`×1) carry narrow, line-scoped `phpcs:ignore` exceptions with the reason
  on the line. See `REVIEW-FIXES.md` Part B-implementation-7.
- **Round 8 (2026-10-13): 160 → 2.** Every remaining warning was resolved by
  either a code change (sanitizer wrappers, `print_r`→`wp_json_encode`,
  `error_log`→`\fastlayer_debug_log`, `set_error_handler`→`@preg_match`,
  `$redirect_to`→`esc_url_raw`, `HTTP_*` sanitizers, unprefixed globals renamed in
  `advanced-cache.php`/`uninstall.php`) or a single-line, technically-justified
  `phpcs:ignore` (early-load superglobals, read-only request params, maintenance
  SQL, `set_time_limit`, `var_export`, fatal/shutdown `error_log`). The two
  remaining rows are `unexpected_markdown_file` (AGENTS.md, dev-only, excluded
  from the ZIP) and `load_plugin_textdomainFound` (intentional; see
  `REVIEW-FIXES.md` A17). The `enqueued_styles_size` runtime check hung in this
  environment and was excluded — it emits no finding, so the result is
  unaffected. See `REVIEW-FIXES.md` Part B-implementation-8.

### Unreleased source changes (2026-10-12 → 2026-10-13) — implemented; rounds 1–2 source-inspected only, rounds 3–7 executed

Rounds 1–2 edits are present in the working copy and were made before the PHP
toolchain was available in this workspace, so they were **source-inspection
verified only**. Rounds 3–7 (below) were made after LocalWP PHP 7.4.30, the
plugin-check-bundled PHPCS, and WP-CLI 2.12.0 became available, so their results
are **actually executed**. The round-7 code pass (AlternativeFunctions /
WP_Filesystem) is documented in `docs/REVIEW-FIXES.md` Part B-implementation-7.
See also Part B-implementation / -3 / -4 / -5 and `tests/QA-CHECKLIST.md` §R.

| Fix | File | What changed | Verified? |
| --- | --- | --- | --- |
| C-2 | `includes/class-admin.php` | `$_GET['settings-updated']` is unslashed + sanitized before comparison; missing value handled | Source inspection only |
| C-1 | `includes/class-database-optimization.php` | `OPTIMIZE TABLE` identifier allowlisted from `SHOW TABLES`, case preserved; `%i` on WP ≥ 6.2, backtick-quoted fallback otherwise | Source inspection only |
| WebP prepare | `includes/class-webp.php` | `call_user_func_array()` prepare → direct `$wpdb->prepare( $sql, $mime_types )` | Source inspection only |

**Round 2 (2026-10-12) — further Plugin Check fixes:**

| Fix | File | What changed | Verified? |
| --- | --- | --- | --- |
| R2-1 translator comment | `includes/class-webp.php` | `translators:` note added for the `%s` in the WebP upload notice | Source inspection only |
| R2-2 escape at output | `includes/class-admin-bar.php` | Notice strings kept raw (`__()`), escaped once with `esc_html()` at output | Source inspection only |
| R2-3 UTC timestamp | `includes/class-bootstrap.php` | `date()` → `gmdate()` in the generated-config comment | Source inspection only |
| R2-4 identifier fallback | `includes/class-database-optimization.php` | One justified, line-scoped `phpcs:ignore` on the WP 6.0–6.1 `OPTIMIZE TABLE` fallback | Source inspection only |
| R2-5 `wp_is_writable()` | `class-admin.php`, `class-bootstrap.php`, `class-page-cache.php`, `class-webp.php` | `is_writable()` → `wp_is_writable()` (9 sites) | Source inspection only |
| R2-6 `wp_delete_file()` | `class-admin.php`, `class-admin-bar.php`, `class-bootstrap.php`, `class-page-cache.php`, `uninstall.php` | `@unlink()` → `wp_delete_file()` (22 sites) | Source inspection only |

**Round 3 (2026-10-12) — executed with the local PHP/WP-CLI toolchain:**

| Fix | File | What changed | Verified? |
| --- | --- | --- | --- |
| R3-1 translator comments | `class-admin.php` (17), `class-database-optimization.php` (6), `class-webp.php` (5) | `translators:` notes on all placeholder-bearing strings | **PHPCS + PCP executed** — `MissingTranslatorsComment` 28 → 0 |
| R3-2 ordered placeholders | `class-database-optimization.php`, `class-admin.php` | Real `sprintf()` strings converted to `%1$d/%2$d`, `%1$s…%4$s` | **Executed** — `UnorderedPlaceholdersText` 8 → 0 |
| R3-3 JS-template tokens | `class-admin.php` (4) | `%word%` JS tokens documented and given line-scoped `phpcs:ignore` (not printf placeholders) | **Executed** |
| R3-4 `%i` version guard | `class-database-optimization.php` | Line-scoped, justified suppression for `%i` (only runs on WP ≥ 6.2) | **Executed** — 1 → 0 |
| R3-5 / R3-6 static-SQL suppressions | `class-database-optimization.php`, `class-webp.php` | `NotPrepared` (3) and `UnescapedDBParameter` (4) suppressed line-by-line with reasons | **Executed** — 7 → 0 |
| R3-7 enqueue fallbacks | `class-optimizer.php` | Hand-built `<link>`/`<script>` fallbacks → pass-through of the original tag | **Executed** — `NonEnqueued*` 4 → 0 |
| R3-8 asset versions | `class-optimizer.php` | `null` → `FASTLAYER_VERSION` in `wp_register_style/script` | **Executed** — `MissingVersion` 2 → 0 |
| R3-9 readme short description | `readme.txt` | Short description line added | **Executed** — warning gone |
| R3-10 line endings | `class-admin.php`, `class-database-optimization.php`, `class-optimizer.php` | Each file normalised to LF (was CRLF + one stray LF) | **Executed** — `Mixed` 3 → 0 |

**Round 4 (2026-10-12) — post-lowercase-rename audit (executed):**

| Fix | File | What changed | Verified? |
| --- | --- | --- | --- |
| R4-1 stale active entry | `active_plugins` option | Re-activated + removed the stale `FastLayer/wp-fastlayer.php` row (plugin was inactive because of it) | **Executed** — option now `["fastlayer/wp-fastlayer.php","plugin-check/plugin.php"]` |
| R4-2 unprefixed bootstrap global | `wp-fastlayer.php` | Removed the legacy `$_wpfl_bootstrap_file` global; bootstrap path inlined | **Executed** — `PrefixAllGlobals` 33 → 32 |
| R4-3 readme folder name | `readme.txt` | `FastLayer` → `fastlayer` (line 17) | **Executed** (doc consistency) |
| R4-4 ZIP root name | `docs/RELEASE-CHECKLIST.md` §5.1 | `FastLayer/` → `fastlayer/` | **Executed** (doc consistency) |

Result of round 4: text-domain rows **gone** (lowercase folder); Plugin Check
**625 → 185 → 184** findings (16 E / 168 W).

See `docs/REVIEW-FIXES.md` Part B-implementation-2 / Part B-implementation-3 /
Part B-implementation-4 for per-finding before/after and the native
`parse_url()`, `rmdir()`, `readfile()`, `unlink()` and `rename()` cases as they
stood after round 4. (Those were later resolved in the round-7 code pass — the
`rmdir` calls were migrated to `WP_Filesystem`, and the seven unavoidable calls
were documented with a line-scoped `phpcs:ignore`; see Part B-implementation-7.)

**Round 5 (2026-10-13) — executed:**

| Fix | File | What changed | Verified? |
| --- | --- | --- | --- |
| R5-1 request-method sanitization | `includes/class-page-cache.php` | Added a `get_request_method()` helper (`isset` + `is_string` guard, then `strtoupper( sanitize_text_field( wp_unslash( … ) ) )`) and pointed both `$_SERVER['REQUEST_METHOD']` call sites at it | **Executed** — `InputNotSanitized` 20 → 16, `MissingUnslash` 10 → 6; total **176** (16 E / 160 W) |

**Round 6 (2026-10-13) — executed:**

| Fix | File | What changed | Verified? |
| --- | --- | --- | --- |
| R-57 cache debug-trace persistence | `includes/class-page-cache.php` | Replaced the dead `function_exists( 'update_transient' )` / `update_transient()` branch with `set_transient( 'fastlayer_cache_debug_trace', $trace, 300 )` | **Executed** — `tests/test-r57-debug-trace-transient.php` PASS on PHP 7.4.30 and 8.2.29; Plugin Check unchanged at **176** |

**Round 7 (2026-10-13) — executed (AlternativeFunctions / WP_Filesystem):**

| Fix | File | What changed | Verified? |
| --- | --- | --- | --- |
| R7-1 filesystem helper | `includes/class-bootstrap.php` | Added `get_filesystem()` (memoised `WP_Filesystem` init) and `delete_empty_directory()` (normalises the path, refuses non-dir/non-empty, **fail-closed** when no filesystem) | **Executed** — `tests/test-filesystem-rmdir.php` PASS on PHP 7.4.30 and 8.2.29; `php -l` clean |
| R7-2 cache dir cleanup | `includes/class-admin-bar.php` | Two `@rmdir()` → `Bootstrap::delete_empty_directory()` | **Executed** — `AlternativeFunctions.file_system_operations_rmdir` 9 → 0; lint clean |
| R7-3 minified-asset dir cleanup | `includes/class-admin.php` | `@rmdir()` → `Bootstrap::delete_empty_directory()` | **Executed** — as above |
| R7-4 legacy `logs/` removal | `includes/class-bootstrap.php` | `@rmdir()` → `self::delete_empty_directory()` | **Executed** — as above |
| R7-5 uninstall cleanup | `uninstall.php` | One `WP_Filesystem()` init + five `@rmdir()` → `$wp_filesystem->rmdir()` (removals skipped when the filesystem is unavailable) | **Executed** — lint clean; Plugin Check 0 E / 160 W. Live deactivate/delete **not re-run** (no disposable site) |
| R7-6 documented exceptions | `advanced-cache.php`, `includes/class-bootstrap.php`, `includes/class-page-cache.php`, `includes/class-webp.php` | Seven line-scoped `phpcs:ignore` for the calls that cannot use a WP helper (pre-WordPress drop-in; atomic `rename`; streaming `readfile`) | **Executed** — Plugin Check `AlternativeFunctions` 16 → **0** |

> Do **not** mark the release ready because these edits are in place. §2 and §3
> must still be green.

---

## 3. Functional regression

Walk `tests/QA-CHECKLIST.md`. At minimum, these must pass on a real site:

| Area | Checklist refs | Status |
| --- | --- | --- |
| Activate / deactivate / reactivate | A-01 … A-10 | **Partial** — A-02/A-03/A-07/A-08 executed 2026-10-12 (drop-in + marked `WP_CACHE` removed/restored, no duplicate); A-01 fresh install, A-04…A-06, A-09, A-10 Not Run |
| `WP_CACHE` warning gone | C-01 … C-06 | **Partial** — C-01/C-02/C-04 executed 2026-10-12 (`defined('WP_CACHE')` true on the next request; no warning on a writable config); C-03/C-05/C-06 Not Run |
| Page cache serve/save/invalidate | D-01 … D-12, E-01 … E-05 | Not Run |
| Preload | F-01 … F-05 | Not Run |
| CSS/JS optimize | G-01 … G-05, H-01 … H-05 | Not Run |
| Lazy load / LQIP | I-01 … I-05 | Not Run |
| WebP | J-01 … J-06 | Not Run |
| CDN / ImageKit | K-01 … K-07 | Not Run |
| Database optimization | L-01 … L-06 | Not Run |
| Uninstall cleanup | N-01 … N-06 | Not Run |
| Priority-1 security regression (§R) | R-01 … R-41 | Not Run (static sub-checks executed — see `tests/QA-CHECKLIST.md` §P) |
| R-57 cache debug-trace persistence (§R) | R-57 | **PASS (2026-10-13)** — `tests/test-r57-debug-trace-transient.php` run with `wp eval-file` on **PHP 7.4.30** and **PHP 8.2.29** (both exit 0); the pre-fix A/B body fails. See `docs/REVIEW-FIXES.md` Part B-implementation-5 “Round 6” and Part B-implementation-6 |
| Filesystem cleanup (§R) | R-59 | **PASS (2026-10-13)** — `tests/test-filesystem-rmdir.php` run with `wp eval-file` on **PHP 7.4.30** and **PHP 8.2.29** (both exit 0): an empty directory is removed, a non-empty dir and a file path are refused. See `docs/REVIEW-FIXES.md` Part B-implementation-7 |

---

## 4. Environment matrix

Test at least the minimum and one current version:

| Environment | Status | Evidence |
| --- | --- | --- |
| PHP 7.4.30 (lowest actually tested) | **Passed** | Executed 2026-10-13 (incl. the round-7 code pass): `php -l` **13/13** clean on both branches, R-57 regression PASS, and the new `tests/test-filesystem-rmdir.php` PASS. Plus maintainer-reported manual compatibility testing (2026-10-12). |
| PHP 8.2.29 (current PHP branch) | **Passed** | Executed 2026-10-13: `php -l` **13/13** clean, R-57 regression PASS and `tests/test-filesystem-rmdir.php` PASS (both via `wp eval-file`, exit 0). |
| PHP below 7.4 | **Blocked** | Not available in the testing environment; no reviewer requirement to test below 7.4 |
| WordPress 6.0 minimum | **Blocked (static only)** | No disposable WP 6.0 environment. Static `wp_functions_compatibility` check → “No errors found” (no function newer than 6.0). |
| Current PHP + current WordPress | **Partial** | Live site runs **PHP 7.4.30 + WordPress 7.1.3**; the same codebase was additionally linted and regression-tested on **PHP 8.2.29** (2026-10-13). |
| Fresh install | **Blocked** | No disposable empty WordPress site available. |
| Upgrade from 1.0.0 (legacy `wp_fastlayer_*`) | **Blocked** | No disposable 1.0.0 site to upgrade from; legacy migration paths are source-inspected and documented only. |
| Apache or LiteSpeed | **Blocked** | Only an **nginx** LocalWP site exists in this environment (no Apache/LiteSpeed). |
| nginx | **Partial** | The audit (activate/deactivate/reactivate, `WP_CACHE`, PHP lint, PHPCS, live Plugin Check, live page-cache serve/save) ran on this LocalWP **nginx** + MySQL 8.4 site; web-server-specific cache serving (D-10, J-04) not exercised |
| Multisite (if claimed) | Not Run | FastLayer does not claim multisite support |
| Debug logging off (default) and on | Not Run | |

> **PHP version policy:** FastLayer declares `Requires PHP: 7.4`. The lowest PHP
> version actually tested is **7.4.30**. PHP versions **below 7.4** have **not**
> been tested and are **not required** by any located WordPress.org review
> feedback (the review message could not be found; see `docs/REVIEW-FIXES.md`).
> Do not claim otherwise, and keep the declared minimum at 7.4.

---

## 5. Build / ZIP structure

Prepare the distributable ZIP. The archive root folder must be `fastlayer/`
(lowercase, matching the WordPress.org slug — see `docs/REVIEW-FIXES.md`
Part B-implementation-2 for the directory-rename note).

### Must include — exact production manifest (18 files)

The production ZIP is rooted at `fastlayer/` and contains **exactly these 18
files**. The list was verified against the working copy on **2026-10-13**, and
the sizes/`sha256` were re-derived after the round-7 AlternativeFunctions /
WP_Filesystem code pass (rows 2, 3, 10, 11, 12, 16 and 17 changed; the count
stays 18). The size and `sha256` prefix are recorded so the assembled archive can
be diffed byte-for-byte before upload. (No ZIP was created as part of this pass.)

| # | Path (under `fastlayer/`) | Bytes | sha256 (first 12) |
| ---: | --- | ---: | --- |
| 1 | `wp-fastlayer.php` | 2 553 | `4ceb44e0ca3b` |
| 2 | `advanced-cache.php` | 11 479 | `bd5d1b1c1dfa` |
| 3 | `uninstall.php` | 6 659 | `cbebfb865de3` |
| 4 | `readme.txt` | 6 103 | `e1885366dd3e` |
| 5 | `assets/css/admin.css` | 44 318 | `965ac2393f69` |
| 6 | `assets/js/admin.js` | 52 237 | `df803da16745` |
| 7 | `assets/js/imagekit-runtime-debug.js` | 6 928 | `0016f751c9e4` |
| 8 | `assets/js/lazyload.js` | 1 037 | `ace5d6b14236` |
| 9 | `assets/js/lqip-frontend.js` | 1 107 | `2a518a7af79c` |
| 10 | `includes/class-admin.php` | 214 082 | `58a82e7b4a18` |
| 11 | `includes/class-admin-bar.php` | 12 205 | `74d0df7b87f5` |
| 12 | `includes/class-bootstrap.php` | 36 557 | `bbb0ded269e5` |
| 13 | `includes/class-cdn.php` | 68 603 | `bedc4b78cbd4` |
| 14 | `includes/class-database-optimization.php` | 65 185 | `202c9f26ee84` |
| 15 | `includes/class-optimizer.php` | 96 658 | `9de5d71bb29a` |
| 16 | `includes/class-page-cache.php` | 70 787 | `5d54ce9f7ea1` |
| 17 | `includes/class-webp.php` | 143 090 | `b503c57bc0db` |
| 18 | `languages/fastlayer.pot` | 30 890 | `8c72beba3f9e` |

> There is **no** `LICENSE` file and **no** `assets/images/` directory in the
> working copy (verified 2026-10-13); the GPLv2+ licence is declared in the
> plugin header and `readme.txt`.

### Must NOT include (excluded from the production ZIP)

The following are development-only and are **excluded** from the production
archive:

- **`AGENTS.md`** — development-only contributor guide.
- **`docs/`** — development-only documentation (`ARCHITECTURE.md`,
  `RELEASE-CHECKLIST.md`, `REVIEW-FIXES.md`).
- **`tests/`** — development-only QA checklist + the `wp eval-file` regression
  scripts (`QA-CHECKLIST.md`, `test-r57-debug-trace-transient.php`,
  `test-filesystem-rmdir.php`).

Also exclude if present: `.git/`, `.github/` dev workflows unless intended for
the directory; `node_modules/`, `vendor/`, `composer.*`; `.bak`,
`.fastlayer-staged`, local `performance.log`; any `wp-config.php` copy.

> The 18-file manifest above **is** the inclusion list; everything not listed is
> excluded. There is **no build script** in the repository, so the exclusions are
> applied by hand (or with `wp dist-archive` plus a `.distignore`) when the
> release ZIP is assembled. Do not add build tooling without maintainer approval.

### Verify

| # | Check | Status | Evidence |
| --- | --- | --- | --- |
| 5.1 | Zip root is `fastlayer/` (not files at root) | Not Run | No ZIP built (documentation pass); the archive root must be set to `fastlayer/` when it is assembled |
| 5.2 | Only production files included | **Verified by manifest (2026-10-13)** | Working copy holds exactly the 18 files listed above; `AGENTS.md`, `docs/`, `tests/` are excluded |
| 5.3 | No secrets / endpoints / credentials | **Verified by inspection (2026-10-13)** | No secrets in source. ImageKit keys are never stored — only the endpoint/URL is persisted (documented in `readme.txt` External Services) |
| 5.4 | `readme.txt` renders correctly (validator) | **PASS (2026-10-13)** | Official WordPress.org Readme Validator: no errors, no warnings; sole note “No donate link was found” |
| 5.5 | ZIP extracts and activates on a clean WP | Not Run | No ZIP built and no disposable clean site (see §4) |

> Do **not** create the ZIP as part of a documentation task. This section is a
> release-time procedure only.

---

## 6. Submission steps (WordPress.org)

1. Confirm all sections above are green.
2. Create the ZIP (release task only).
3. Submit via the WordPress.org plugin submission process (SVN/`stable tag`
   update) following the directory's current instructions.
4. After approval, verify the live listing: version, screenshots, description,
   and the External Services section (ImageKit) are accurate.
5. Tag the release in the project's version control (once a repo exists).

---

## 7. Post-release

| # | Task | Status |
| --- | --- | --- |
| 7.1 | Update `Stable tag` and changelog already shipped | Not Run |
| 7.2 | Open the next dev version bump issue | Not Run |
| 7.3 | Re-baseline Plugin Check numbers in `docs/REVIEW-FIXES.md` | Not Run |
| 7.4 | Close review items resolved in this release | Not Run |
| 7.5 | Monitor support forum for regressions | Not Run |

---

## 8. Rollback plan

- Keep the previous released ZIP available.
- If a fatal is reported, revert to the previous `Stable tag` in SVN immediately.
- Because FastLayer edits `wp-config.php` and the drop-in, a rollback must also
  verify: `WP_CACHE` state, `advanced-cache.php` presence/ownership, and that the
  cache directory is intact.
- Manual recovery notes live in the docblocks of `Bootstrap::write_wp_config()`
  (a failed config write keeps a backup and reports its path).
