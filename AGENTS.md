# AGENTS.md — Working on FastLayer

Guidance for developers and AI agents contributing to the **FastLayer** WordPress
plugin. Read this before changing code.

---

## 1. What this plugin is

FastLayer is a WordPress performance plugin. It ships one job per feature area:

- Page cache (served through a `advanced-cache.php` drop-in).
- Cache preloading (background batches).
- HTML/CSS/JS minification and combination.
- Lazy loading, LQIP placeholders, WebP conversion, ImageKit/CDN image delivery.
- Database cleanup tools.

- **Plugin slug / folder:** the local folder and the WordPress.org slug are both
  **`fastlayer`** (lowercase). The folder was renamed from `FastLayer` to
  `fastlayer` on 2026-10-12 (round 4). Renaming the folder deactivates the
  plugin, because WordPress stores `folder/mainfile.php` in the `active_plugins`
  option: after the rename the old `FastLayer/wp-fastlayer.php` entry no longer
  resolves, so reactivate the plugin and remove the stale capitalized entry.
  The lowercase text domain was always correct and stays `fastlayer`; with the
  lowercase folder, Plugin Check no longer reports a text-domain mismatch. See
  `docs/REVIEW-FIXES.md` Part B-implementation-4.
- **Main file:** `wp-fastlayer.php`
- **Text domain:** `fastlayer` (never change)
- **Current version:** `1.1.0` (kept in three places — see §7)
- **Requires WordPress:** 6.0+
- **Requires PHP:** 7.4+
- **License:** GPLv2 or later

Do **not** change the plugin slug, the text domain, or any public API/shortcode/
filter/option name without an explicit, documented migration.

---

## 2. Repository layout

```
fastlayer/
├── wp-fastlayer.php              # Main plugin file: header, constants, autoloader, bootstrap
├── advanced-cache.php            # Template copied to wp-content/advanced-cache.php on activation
├── uninstall.php                 # Full cleanup on delete
├── readme.txt                    # WordPress.org readme
├── AGENTS.md                     # This file
├── assets/
│   ├── css/admin.css
│   ├── js/admin.js
│   ├── js/imagekit-runtime-debug.js
│   ├── js/lazyload.js
│   └── js/lqip-frontend.js
├── includes/
│   ├── class-bootstrap.php            # Lifecycle, drop-in install, wp-config writes, notices
│   ├── class-page-cache.php           # Serving + saving cached pages, preload batches, invalidation
│   ├── class-optimizer.php            # CSS/JS minify, combine, defer, smart delay
│   ├── class-webp.php                 # WebP generation, .htaccess rules, LQIP
│   ├── class-cdn.php                  # CDN / ImageKit URL rewriting, LCP preload
│   ├── class-admin.php                # Settings screen, admin-post/ajax handlers
│   ├── class-admin-bar.php            # Admin bar cache actions
│   └── class-database-optimization.php# DB cleanup routines
├── languages/fastlayer.pot
├── docs/                          # Architecture, review fixes, release checklist
└── tests/                         # QA checklist
```

---

## 3. Coding standards

- Follow **WordPress Coding Standards (WPCS)** for PHP. Run PHPCS with the
  `WordPress`, `WordPress-Core`, `WordPress-Docs` and `WordPress-Extra` rulesets
  when a PHP toolchain is available.
- Follow the existing file layout: namespaced classes in `includes/`, one class
  per file, file name `class-<kebab-case-name>.php`.
- The autoloader in `wp-fastlayer.php` maps `FastLayer\Foo_Bar` to
  `includes/class-foo-bar.php`. Keep the mapping intact.
- Indent with 4 spaces in PHP, 2 spaces in JS/CSS (match surrounding code).
- No short tags. No closing `?>` at the end of pure-PHP files.
- Do not reformat unrelated code in a change.

> **Line endings:** `class-admin.php`, `class-database-optimization.php` and
> `class-optimizer.php` are **LF-only** (normalised in round 3; see
> `docs/REVIEW-FIXES.md` B16 / R3-10). They no longer mix CRLF and LF, and the
> current Plugin Check run reports no "Mixed line endings" finding. When you
> touch any file, keep its existing ending (or save the whole file with one
> consistent ending) so the finding does not come back, but keep the diff
> reviewable.

---

## 4. Security rules (mandatory)

Every user-supplied value must be **sanitized on input, validated before use,
and escaped on output**.

- **Capabilities:** admin screens and handlers must check
  `current_user_can( 'manage_options' )` (or the correct capability).
- **Nonces:** every form/AJAX/admin-post action that changes state must verify a
  nonce with `check_admin_referer()` / `check_ajax_referer()` / `wp_verify_nonce()`.
  Plugin Check currently flags ~29 "nonce verification recommended" reads; when
  touching a `$_GET`/`$_POST`/`$_REQUEST` read, add the nonce check rather than
  suppressing the warning.
- **Sanitize:** use `sanitize_text_field()`, `absint()`, `sanitize_key()`,
  `esc_url_raw()`, `wp_unslash()` (always unslash before sanitizing) etc.
- **Escape on output:** `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- **SQL:** never interpolate variables into SQL. Use `$wpdb->prepare()` for any
  query containing a variable. Do not suppress `PreparedSQL.NotPrepared`; fix it.
- **File operations:** prefer WP filesystem helpers where practical. Any direct
  file write must (a) validate the target stays inside a plugin-owned directory,
  (b) check the write result, (c) verify the content round-trips, and (d) record
  a failure via `Bootstrap::record_write_failure()`.
- **No secrets in code.** ImageKit keys are never stored; only the endpoint is.

See `docs/ARCHITECTURE.md` for the write-safe patterns already in place.

---

## 5. The `wp-config.php` / drop-in contract

This is the most dangerous part of the plugin. Never weaken these guarantees:

1. `wp-config.php` is read via `Bootstrap::read_wp_config()`, which refuses
   non-files, unreadable files, write-only files, empty content, and content that
   does not start with `<?php`. **A failed read must never be treated as empty
   content** (that would blank the config).
2. The config is backed up to the system temp dir before editing and written
   atomically through a staged file + `rename()`.
3. `WP_CACHE` is only ever removed when the exact ownership marker
   `wp-fastlayer:owned-wp-cache` is present on the same line. Never remove a
   `WP_CACHE` define you did not write.
4. The `advanced-cache.php` drop-in is only overwritten/removed when
   `Bootstrap::is_our_advanced_cache_dropin()` proves ownership (bundled hash,
   marker, or FastLayer function names, including legacy `wp_fastlayer_*`).
   A foreign caching plugin's drop-in is never touched.
5. `.htaccess` rules are always wrapped in markers so they can be removed
   cleanly, and never clobber a hand-edited file.

---

## 6. Backward compatibility / legacy migration

FastLayer 1.1.0 renamed everything from `wp_fastlayer_*` to `fastlayer_*`.
Legacy handling is **required** and must be preserved:

- Option: `Bootstrap::migrate_legacy_state()` copies `wp_fastlayer_options` to
  `fastlayer_options` once, and the `mirror_legacy_options` hooks keep the rows
  in sync until the next settings save.
- Cron: `wp_fastlayer_preload_cache` / `wp_fastlayer_preload_batch` are migrated
  and cleared, not abandoned.
- Transients, the drop-in owner check, and uninstall all understand both the new
  and legacy key names.
- `advanced-cache.php` and `uninstall.php` intentionally run before/outside full
  WordPress and use **prefixed-but-global** variables and native `parse_url()`.
  Do not "fix" these without understanding the bootstrap order.
- `load_plugin_textdomain()` is retained for older WordPress compatibility; if a
  reviewer asks, the correct fix is to remove it and rely on the
  `Domain Path: /languages` header + `Requires at least: 6.0`, but confirm with
  the maintainer first.

When adding a rename, always ship a migration path in the same change.

---

## 7. Versioning

The version lives in **three** places and must stay in sync:

| File | Line |
| --- | --- |
| `wp-fastlayer.php` | `Version:` header |
| `wp-fastlayer.php` | `define( 'FASTLAYER_VERSION', ... )` |
| `readme.txt` | `Stable tag:` |

`readme.txt` also has a `Tested up to:` field — keep it at a WordPress version
that is actually current when releasing. It currently reads `7.1`, which matches
the WordPress 7.1.3 running in the round-4 audit environment; still confirm it
against the current WordPress release before shipping.

The changelog lives in `readme.txt`. Add a new section per release; do not
rewrite historical entries.

---

## 8. Testing expectations

**A test claim is only valid if the test was actually executed.** Never write
"passes" for a check that was not run — mark it "Not Run", "Blocked", or
"Not Yet Tested" instead. Distinguish clearly between:

- code inspection (reading the source),
- automated test results (a command that ran and produced output),
- manual tests (performed by a human in a live site).

Before submitting a change:

1. Run `node --check` on every changed `assets/js/*.js` file.
2. Run PHPCS (WPCS) — if PHP/PHPCS are unavailable, say so in the PR/notes and
   do not claim it passed.
3. Run **Plugin Check** (the WordPress.org "Plugin Check" plugin or the PCP
   scanner) and compare against `docs/REVIEW-FIXES.md`.
4. Walk the relevant sections of `tests/QA-CHECKLIST.md`.
5. Confirm nothing in `docs/RELEASE-CHECKLIST.md` regressed.

### Tool availability in this environment

Verified in the current workspace (do not assume otherwise without re-checking):

- **Available:** `node` (v22.23.2), `npm`, `npx`, `git`.
- **Available — LocalWP site toolchain (since round 3):**
  - **PHP 7.4.30 CLI:** `%APPDATA%\Local\lightning-services\php-7.4.30+6\bin\win64\php.exe`.
    It loads no `php.ini`, so enable extensions at runtime with
    `-d extension_dir=<php>\ext -d extension=php_mysqli.dll` (plus `pdo_mysql`,
    `mbstring`, `curl`, `openssl`, `gd2`, `gettext`, `fileinfo`, `exif`, `intl`).
  - **WP-CLI 2.12.0:** `wp-cli.phar` (present at both `C:\xampp\php\wp-cli.phar`
    and `C:\Program Files (x86)\Local\resources\extraResources\bin\wp-cli\wp-cli.phar`).
    Run against the live site with `--path="<site>\app\public"` plus
    `-d extension=php_mysqli.dll -d mysqli.default_port=10027` (Local's MySQL
    port — verify per site).
  - **Plugin Check 2.1.0** (installed as a plugin) and its **bundled PHPCS/WPCS**.
- **Not available:** `composer`, `rg` (ripgrep).
- PHP lint (`php -l`), `node --check`, WPCS and Plugin Check **can and should** be
  run here; PHPUnit and full live-site functional flows still need a real site.
  Document anything not run rather than inventing results.
- **PHP testing policy:** the declared minimum stays `Requires PHP: 7.4`. The
  lowest PHP version actually tested is **7.4.30** (manual compatibility testing,
  maintainer-reported). PHP versions below 7.4 are **not tested** and are not
  required by any located reviewer feedback. Never claim otherwise.

---

## 9. Root-cause fixes and regression tests

- Fix the **root cause**, not the symptom. Do not silence a linter rule with an
  inline ignore when the finding is real.
- When you fix a bug, add a regression note (or test, once a PHP test harness
  exists) that would have caught it.
- Keep changes focused: one concern per change set.
- Do not make unrelated changes to plugin code while doing documentation or
  review work.

---

## 10. Release rules

See `docs/RELEASE-CHECKLIST.md` for the full procedure. Non-negotiables:

- Bump all three version locations together.
- Update `readme.txt` changelog and upgrade notice.
- Do not ship a version whose Plugin Check report is worse than the previous one.
- **Do not** create or upload a release ZIP, and do not run the release process
  as part of a code/doc task, unless explicitly asked.
- Never edit `wp-config.php` in a repo/working copy by hand as part of a plugin
  change; the plugin edits the live config at runtime through the safe path.
- Do not install dependencies or change tooling configuration without approval.

---

## 11. Things you must never do

- Change the plugin slug, text domain, or public option/filter/shortcode names.
- Edit `wp-config.php` directly, or bypass `read_wp_config()`/the atomic writer.
- Delete or overwrite files you did not create without investigating first
  (another agent or the user may be mid-work).
- Remove legacy-migration code.
- Claim an unexecuted test passed.
- Create a release ZIP or publish anything.
