# REVIEW-HISTORY-MATRIX.md — FastLayer review rounds

One row per remediation round, with how each result was verified. “Executed”
means a command ran here and produced the cited output; “Source” means code
inspection only. Plugin Check counts are the **authoritative live scans**
(`wp plugin check fastlayer`, plugin-check 2.1.0).

| Round | Date | Scope | Method | Plugin Check | Key changes | Doc reference |
| --- | --- | --- | --- | --- | --- | --- |
| R1 | 2026-10-12 | First Plugin Check fixes | Source | — | `OPTIMIZE TABLE` identifier hardening; `$_GET['settings-updated']` sanitized; WebP `$wpdb->prepare()`; `is_writable`→`wp_is_writable`; `@unlink`→`wp_delete_file` | `REVIEW-FIXES.md` Part B-implementation |
| R2 | 2026-10-12 | Second Plugin Check pass | Source | — | Translator comment (WebP); admin-bar escape moved to output; `date()`→`gmdate()`; one scoped suppression on the `%i` fallback | `REVIEW-FIXES.md` Part B-implementation-2 |
| R3 | 2026-10-12 | Toolchain available | **Executed** | 679 → 625 | Translator comments; numbered placeholders; enqueue fallbacks; asset versions; readme short description; line-ending normalisation | `REVIEW-FIXES.md` Part B-implementation-3 |
| R4 | 2026-10-12 | Folder rename `FastLayer`→`fastlayer` | **Executed** | 625 → 185 → 184 | Cleared 440 text-domain rows; removed legacy bootstrap global; doc name fixes | `REVIEW-FIXES.md` Part B-implementation-4 |
| R5 | 2026-10-13 | Error/security audit | **Executed** | 184 → 176 | `get_request_method()` helper (sanitize/unslash `REQUEST_METHOD`) | `REVIEW-FIXES.md` Part B-implementation-5 |
| R6 | 2026-10-13 | R-57 cache debug trace | **Executed** | 176 | Dead `update_transient()` branch → `set_transient()`; added `tests/test-r57-debug-trace-transient.php` | `REVIEW-FIXES.md` Part B-implementation-5 “Round 6” |
| R7 | 2026-10-13 | AlternativeFunctions / WP_Filesystem | **Executed** | 176 → 160 (0 E / 160 W) | `rmdir` migrated to `WP_Filesystem`; seven unavoidable native calls given documented exceptions; `tests/test-filesystem-rmdir.php` | `REVIEW-FIXES.md` Part B-implementation-7 |
| R8 | 2026-10-13 | Warning cleanup (160 → 2) | **Executed** | 160 (0 E) → **2** | Sanitizer wrappers, `print_r`→`wp_json_encode`, `error_log`→`\fastlayer_debug_log`, `set_error_handler`→`@preg_match`, `$redirect_to`→`esc_url_raw`, HTTP-header sanitizers, unprefixed globals renamed, plus line-scoped justified ignores | `RELEASE-CHECKLIST.md` §2.4 / §5 (see reconciliation below) |
| R9 | 2026-10-15 | Findings A–G + docs + skills + CI | **Executed** | **0 E / 3 W** | `Bootstrap::url_to_path()` unification; `config.php`→`config.json` + self-heal; CDN escaping + `sanitize_css_url()` + srcset regex; ordered buffer flush; 3 new regression tests; 7 skills; CI workflow | `docs/AUDIT-FINDINGS.md` |

## Verification method legend

- **Executed** — PHP lint, `node --check`, WPCS/PHPCS, `wp plugin check`,
  `wp eval-file` regression scripts, live GET/HEAD where applicable.
- **Source** — direct source inspection; no runtime proof (rounds 1–2 only).

## Consistency reconciliation

Three inconsistencies were found in the existing docs and are corrected here
(authoritatively) rather than by rewriting history:

1. **`RELEASE-CHECKLIST.md` §0.1 says “This folder is not a git repo (verified).”**
   The plugin folder **is** a git repository now (branch `main`, HEAD
   `1713fff`). The note is stale. `docs/RELEASE-CHECKLIST.md` is updated to
   reflect the actual state.

2. **`RELEASE-CHECKLIST.md` §2.4 records a final “0 E / 2 W” and cites
   “`REVIEW-FIXES.md` Part B-implementation-8”.**
   `docs/REVIEW-FIXES.md` has **no** Part B-implementation-8 section, so that
   citation is dangling, and the current live scan is **0 E / 3 W**, not 2. The
   third warning is `hidden_files` (`.gitignore`), a file that did not exist in
   the round-8 environment (it arrived with the git repository). Neither warning
   count reflects a code defect.

3. **Round 8 has no `REVIEW-FIXES.md` section.** Rounds 3–7 each have a
   `Part B-implementation-N`; round 8 is only described in
   `RELEASE-CHECKLIST.md`. This matrix is the consolidated index; a full round-8
   narrative is intentionally not invented here (no per-fix record survives),
   and the changes it lists are verified present in the current source
   (e.g. `\fastlayer_debug_log()` calls, `wp_json_encode()` in the config
   writer, sanitized `HTTP_*` reads).

## Current standing (after R9)

- **Plugin Check:** 0 ERROR / 3 WARNING (`hidden_files`, `unexpected_markdown_file`,
  `load_plugin_textdomainFound`). All three are packaging/environment-level; none
  is a code defect. The count is unchanged from the R9 baseline.
- **Regression tests:** all six `tests/*.php` scripts **executed PASS** (exit 0)
  via `wp eval-file`.
- **WPCS:** per-sniff delta introduces **no new rule category**.
- **Version:** `1.1.0` in all three locations.
